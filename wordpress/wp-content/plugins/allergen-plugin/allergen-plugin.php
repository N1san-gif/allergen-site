<?php
/*
Plugin Name: Allergen Profile Ultimate
Version: 33.0
Description: Final version: 100% English UI, Smart Generics, Synonym Auto-fix, Recipe Shortcode Grid + Protected Wikipedia API Tooltip.
*/

// ==========================================
// 1. DATA GATHERING & SMART DICTIONARY
// ==========================================
function allergen_get_user_forbidden_words($user_id) {
    global $wpdb;
    $saved_ids = $wpdb->get_col($wpdb->prepare("SELECT allergen_id FROM user_allergens_map WHERE user_id = %d", $user_id));
    if (empty($saved_ids)) return [];

    $placeholders = implode(',', array_fill(0, count($saved_ids), '%d'));
    $allergen_data = $wpdb->get_results($wpdb->prepare("
        SELECT a.allergen_name, GROUP_CONCAT(al.alias_name) as aliases
        FROM allergens a
        LEFT JOIN allergen_aliases al ON a.allergen_id = al.allergen_id
        WHERE a.allergen_id IN ($placeholders)
        GROUP BY a.allergen_id
    ", $saved_ids));

    $forbidden_words = [];

    $smart_aliases = [
        'cow milk'     => ['milk'],
        'goat milk'    => ['milk'],
        'chicken egg'  => ['egg'],
        'soybean'      => ['soy'],
        'milk protein' => ['milk'],
        'wheat flour'  => ['wheat', 'flour'],
        'mustard powder'=> ['mustard'],
        'soy lecithin' => ['soy', 'lecithin']
    ];

    foreach ($allergen_data as $row) {
        $name = mb_strtolower(trim($row->allergen_name));
        $forbidden_words[] = $name;

        if (isset($smart_aliases[$name])) {
            foreach ($smart_aliases[$name] as $generic_word) {
                $forbidden_words[] = $generic_word;
            }
        }

        if ($row->aliases) {
            $aliases = explode(',', $row->aliases);
            foreach ($aliases as $alias) {
                $clean_alias = mb_strtolower(trim($alias));
                $forbidden_words[] = $clean_alias;
                
                if (isset($smart_aliases[$clean_alias])) {
                    foreach ($smart_aliases[$clean_alias] as $generic_word) {
                        $forbidden_words[] = $generic_word;
                    }
                }
            }
        }
    }
    return array_unique($forbidden_words);
}

function allergen_find_in_text($text, $forbidden_words) {
    if (empty($forbidden_words)) return null;
    $clean_content = mb_strtolower(wp_strip_all_tags($text));
    foreach ($forbidden_words as $word) {
        if (mb_strpos($clean_content, $word) !== false) {
            return $word;
        }
    }
    return null;
}

add_action('delete_user', 'allergen_profile_cleanup_on_user_delete');
function allergen_profile_cleanup_on_user_delete($user_id) {
    global $wpdb;
    $wpdb->delete('user_allergens_map', array('user_id' => $user_id), array('%d'));
}

// ==========================================
// 2. REGISTRATION & ACTIVATION FLOW
// ==========================================
add_action('init', 'allergen_custom_process_registration');
function allergen_custom_process_registration() {
    if (isset($_POST['allergen_custom_register']) && !is_user_logged_in()) {
        $username = sanitize_user($_POST['reg_username']);
        $email    = sanitize_email($_POST['reg_email']);
        $password = $_POST['reg_password'];

        if (username_exists($username)) {
            wp_die('Error: This username already exists. <br><br><a href="'.home_url().'">Return to site</a>');
        }
        if (email_exists($email)) {
            wp_die('Error: This Email is already registered. <br><br><a href="'.home_url().'">Return to site</a>');
        }
        if (empty($password) || strlen($password) < 6) {
            wp_die('Error: Password must contain at least 6 characters. <br><br><a href="'.home_url().'">Return to site</a>');
        }

        $user_id = wp_create_user($username, $password, $email);

        if (!is_wp_error($user_id)) {
            $activation_key = wp_generate_password(20, false);
            update_user_meta($user_id, 'allergen_activation_key', $activation_key);
            update_user_meta($user_id, 'allergen_is_activated', '0');

            $activation_link = add_query_arg(['allergen_activate' => $activation_key, 'uid' => $user_id], home_url('/'));

            $subject = 'Account Registration Confirmation';
            $message = "Welcome, $username!\n\nTo complete your registration and gain access to your individual allergen profile, please click on the following link:\n$activation_link\n\nIf you did not register on our site, please ignore this email.";
            
            wp_mail($email, $subject, $message);

            wp_die('<h3>Registration almost complete!</h3><p>We have sent an email to <b>' . esc_html($email) . '</b>. Please click the link in the email to activate your account.</p><a href="'.home_url().'">Go to homepage</a>');
        } else {
            wp_die($user_id->get_error_message());
        }
    }
}

add_action('init', 'allergen_process_activation_link');
function allergen_process_activation_link() {
    if (isset($_GET['allergen_activate']) && isset($_GET['uid'])) {
        $user_id = intval($_GET['uid']);
        $key = sanitize_text_field($_GET['allergen_activate']);
        $saved_key = get_user_meta($user_id, 'allergen_activation_key', true);

        if ($key === $saved_key && !empty($saved_key)) {
            update_user_meta($user_id, 'allergen_is_activated', '1');
            delete_user_meta($user_id, 'allergen_activation_key');
            wp_die('<h3>Success!</h3><p>Your account has been successfully activated! You can now log in.</p><a href="'.home_url().'">Go to site and log in</a>');
        } else {
            wp_die('Error: Invalid or expired activation key. <a href="'.home_url().'">Go to homepage</a>');
        }
    }
}

add_filter('wp_authenticate_user', 'allergen_block_unverified_login', 10, 2);
function allergen_block_unverified_login($user, $password) {
    if (is_a($user, 'WP_User')) {
        $is_activated = get_user_meta($user->ID, 'allergen_is_activated', true);
        if ($is_activated === '0') {
            return new WP_Error('not_activated', '<strong>Error</strong>: Your account has not been activated yet. Please check your email (including the Spam folder).');
        }
    }
    return $user;
}

// ==========================================
// 3. FRONTEND MODAL & JS LOGIC
// ==========================================
add_action('wp_head', function() {
    if (is_user_logged_in()) {
        global $wpdb;
        $user_id = get_current_user_id();
        $saved_ids = $wpdb->get_col($wpdb->prepare("SELECT allergen_id FROM user_allergens_map WHERE user_id = %d", $user_id));
        if (!empty($saved_ids)) {
            $placeholders = implode(',', array_fill(0, count($saved_ids), '%d'));
            $allergen_data = $wpdb->get_results($wpdb->prepare("
                SELECT a.allergen_name, GROUP_CONCAT(al.alias_name) as aliases
                FROM allergens a LEFT JOIN allergen_aliases al ON a.allergen_id = al.allergen_id
                WHERE a.allergen_id IN ($placeholders) GROUP BY a.allergen_id
            ", $saved_ids));
            echo "<script>window.userRestrictions = " . json_encode($allergen_data) . ";</script>";
        }
    }
});

add_action('wp_footer', 'allergen_profile_final_integrated_render');
function allergen_profile_final_integrated_render() {
    echo '<div id="allergen-trigger-icon">⚠️</div>';
    echo '<div id="allergen-modal-overlay"><div class="allergen-modal-window"><span id="close-allergen-modal">&times;</span>';

    if (!is_user_logged_in()) {
        ?>
        <div style="text-align: center; padding: 10px 20px;">
            <h3 style="font-size: 24px; color: #d9534f; margin-bottom: 15px;">🔒 Authentication Required</h3>
            <p style="font-size: 14px; color: #555; margin-bottom: 25px;">Create your individual allergen profile to protect yourself.</p>
            
            <div class="auth-tabs" style="display: flex; justify-content: center; margin-bottom: 25px; gap: 15px;">
                <button type="button" id="tab-login" class="auth-tab-btn active" onclick="switchAuthTab('login')">Log In</button>
                <button type="button" id="tab-register" class="auth-tab-btn" onclick="switchAuthTab('register')">Register</button>
            </div>

            <div id="form-login" class="auth-form-container" style="max-width: 400px; margin: 0 auto; background: #f9f9f9; padding: 30px; border-radius: 10px; border: 1px solid #ddd; text-align: left;">
                <form method="post" action="<?php echo esc_url(site_url('wp-login.php', 'login_post')); ?>">
                    <label style="display:block; margin-bottom:5px; font-size:13px; font-weight:bold;">Username or Email:</label>
                    <input type="text" name="log" required style="width:100%; padding:12px; margin-bottom:20px; border:1px solid #ccc; border-radius:5px;">
                    
                    <label style="display:block; margin-bottom:5px; font-size:13px; font-weight:bold;">Password:</label>
                    <input type="password" name="pwd" required style="width:100%; padding:12px; margin-bottom:20px; border:1px solid #ccc; border-radius:5px;">
                    
                    <input type="hidden" name="redirect_to" value="<?php echo esc_url(home_url()); ?>">
                    <button type="submit" class="save-btn" style="margin-top:0; padding: 15px;">Log In</button>
                </form>
            </div>

            <div id="form-register" class="auth-form-container" style="display: none; max-width: 400px; margin: 0 auto; background: #fff0f0; padding: 30px; border-radius: 10px; border: 1px solid #ffcccc; text-align: left;">
                <form method="post" action="">
                    <label style="display:block; margin-bottom:5px; font-size:13px; font-weight:bold;">Username:</label>
                    <input type="text" name="reg_username" required style="width:100%; padding:12px; margin-bottom:15px; border:1px solid #ccc; border-radius:5px;">
                    
                    <label style="display:block; margin-bottom:5px; font-size:13px; font-weight:bold;">Email Address:</label>
                    <input type="email" name="reg_email" required style="width:100%; padding:12px; margin-bottom:15px; border:1px solid #ccc; border-radius:5px;">
                    
                    <label style="display:block; margin-bottom:5px; font-size:13px; font-weight:bold;">Password (min. 6 characters):</label>
                    <input type="password" name="reg_password" minlength="6" required style="width:100%; padding:12px; margin-bottom:20px; border:1px solid #ccc; border-radius:5px;">
                    
                    <button type="submit" name="allergen_custom_register" class="move-btn" style="width:100%; background:#ff4d4d; color:white; border:none; padding:15px;">Create Account</button>
                </form>
            </div>
        </div>
        <?php
    } else {
        global $wpdb;
        $user_id = get_current_user_id();
        $saved_ids = $wpdb->get_col($wpdb->prepare("SELECT allergen_id FROM user_allergens_map WHERE user_id = %d", $user_id));
        $all_groups = $wpdb->get_results("SELECT group_id, group_name FROM allergen_groups ORDER BY group_name ASC");
        $all_allergens = $wpdb->get_results("
            SELECT a.allergen_id, a.allergen_name, a.group_id, g.group_name, GROUP_CONCAT(al.alias_name) as aliases
            FROM allergens a LEFT JOIN allergen_groups g ON a.group_id = g.group_id LEFT JOIN allergen_aliases al ON a.allergen_id = al.allergen_id
            GROUP BY a.allergen_id ORDER BY a.allergen_name ASC
        ");
        ?>
        <h3 class="modal-title">My Individual Allergen Profile</h3>
        <div class="filter-row">
            <div class="filter-group"><label>SEARCH:</label><input type="text" id="allergen-search" placeholder="Type to search allergens..."></div>
            <div class="filter-group">
                <label>CATEGORY:</label>
                <select id="group-filter">
                    <option value="all">All Groups</option>
                    <?php foreach ($all_groups as $g) : ?><option value="<?php echo esc_attr($g->group_name); ?>"><?php echo esc_html($g->group_name); ?></option><?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="transfer-container">
            <div class="transfer-column">
                <label class="col-label">Available Items (Click to select)</label>
                <div id="available-items-box" class="structured-box">
                    <?php foreach ($all_allergens as $a) : 
                        $grp = $a->group_name ? $a->group_name : 'Other';
                        $isHidden = in_array($a->allergen_id, $saved_ids) ? 'style="display:none"' : ''; ?>
                        <div class="list-item-full" data-id="<?php echo $a->allergen_id; ?>" data-group="<?php echo esc_attr($grp); ?>" data-name="<?php echo esc_attr($a->allergen_name); ?>" data-aliases="<?php echo esc_attr($a->aliases); ?>" <?php echo $isHidden; ?>><?php echo esc_html($a->allergen_name); ?></div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <div class="transfer-controls">
                <button type="button" id="btn-add-allg" class="move-btn" title="Add only selected items">Add Selected &raquo;</button>
                <button type="button" id="btn-add-all-allg" class="move-btn" title="Add all visible items">Add All &raquo;</button>
                <div style="height: 15px;"></div>
                <button type="button" id="btn-remove-allg" class="move-btn" title="Remove only selected items">&laquo; Remove Selected</button>
                <button type="button" id="btn-remove-all-allg" class="move-btn" title="Remove all items from your list">&laquo; Remove All</button>
            </div>
            
            <div class="transfer-column">
                <label class="col-label">My Restrictions</label>
                <div id="structured-selected-display" class="structured-box"></div>
            </div>
        </div>
        <form method="post" id="allergen-hidden-form">
            <div class="protection-settings" style="margin-top: 20px; padding: 20px; background: #fdf2f2; border-radius: 8px; border: 1px solid #ffcccc;">
                <label style="font-weight: bold; color: #333; margin-bottom: 15px; display: block; border-bottom: 1px solid #f5dcdc; padding-bottom: 10px;">🛡️ Protection Settings (Independent):</label>
                <div class="toggle-row">
                    <span class="toggle-label-text">1. Highlight warnings inside recipe text & cards</span>
                    <label class="switch"><input type="checkbox" name="prot_highlight" value="1" <?php checked(get_user_meta($user_id, 'allergen_setting_highlight', true), '1'); ?>><span class="slider round"></span></label>
                </div>
                <div class="toggle-row">
                    <span class="toggle-label-text">2. Strict Mode: Hide dangerous recipes entirely</span>
                    <label class="switch"><input type="checkbox" name="prot_strict" value="1" <?php checked(get_user_meta($user_id, 'allergen_setting_strict', true), '1'); ?>><span class="slider round"></span></label>
                </div>
            </div>
            <div id="hidden-inputs-container"></div>
            <button type="submit" name="save_db_allergens" class="save-btn">SAVE CHANGES</button>
            
            <div style="text-align:center; margin-top: 15px;">
                <a href="<?php echo wp_logout_url(home_url()); ?>" style="color: #888; text-decoration: underline; font-size: 14px;">Log Out</a>
            </div>
        </form>
        <?php
    }
    echo '</div></div>';
    ?>

<style>
* { box-sizing: border-box; } 
#allergen-trigger-icon { position: fixed; bottom: 30px; right: 30px; background: #ff4d4d; color: white; width: 65px; height: 65px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 26px; cursor: pointer; z-index: 999999; box-shadow: 0 5px 20px rgba(0,0,0,0.3); transition: 0.3s; }
#allergen-trigger-icon:hover { transform: scale(1.1); }
#allergen-modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.75); z-index: 9999999; backdrop-filter: blur(5px); }
.allergen-modal-window { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; padding: 40px; border-radius: 15px; width: 1100px; max-width: 95vw; max-height: 90vh; overflow-y: auto; box-shadow: 0 25px 70px rgba(0,0,0,0.6); font-family: sans-serif; }
.modal-title { margin-top:0; border-bottom:2px solid #eee; padding-bottom:15px; font-size: 22px; }
#close-allergen-modal { position: absolute; top: 20px; right: 25px; font-size: 32px; cursor: pointer; color: #ccc; transition: 0.2s; }
#close-allergen-modal:hover { color: #333; }

.auth-tab-btn { padding: 12px 30px; border: 2px solid transparent; background: #f1f3f5; cursor: pointer; border-radius: 8px; font-weight: bold; font-size: 16px; color: #555; transition: all 0.2s ease; }
.auth-tab-btn.active { background: #ff4d4d; color: white; border-color: #ff4d4d; box-shadow: 0 4px 10px rgba(255, 77, 77, 0.3); }
.auth-tab-btn:hover:not(.active) { background: #e9ecef; }

.filter-row { display: flex; gap: 30px; margin-bottom: 25px; background: #f4f7f6; padding: 20px; border-radius: 10px; }
.filter-group { flex: 1; }
.filter-group label { display: block; font-size: 12px; font-weight: 700; color: #555; margin-bottom: 8px; text-transform: uppercase; }
.filter-group input, .filter-group select { width: 100%; padding: 12px; border: 1px solid #ced4da; border-radius: 6px; }
.transfer-container { display: flex; gap: 20px; align-items: stretch; }
.transfer-column { flex: 1; min-width: 0; display: flex; flex-direction: column; }
.col-label { font-size: 14px; font-weight: 700; margin-bottom: 12px; display: block; color: #333; }
.structured-box { width: 100%; height: 380px; border: 1px solid #ced4da; border-radius: 8px; padding: 15px; background: #fff; overflow-y: auto; line-height: 1.8; }
.list-item-full { display: block; width: 100%; padding: 8px 12px; background: #f8f9fa; border: 1px solid #eee; margin-bottom: 4px; border-radius: 4px; cursor: pointer; font-size: 13px; transition: 0.2s; }
.list-item-full:hover { background: #e9ecef; }
.list-item-full.selected-for-add { background: #007bff !important; color: white; border-color: #007bff; }
.group-row { margin-bottom: 15px; padding-bottom: 5px; border-bottom: 1px solid #f0f0f0; }
.group-title { font-weight: bold; color: #2c3e50; font-size: 13px; }
.tag-item { background: #f1f3f5; border: 1px solid #ddd; padding: 4px 10px; border-radius: 4px; margin: 3px; font-size: 13px; cursor: pointer; display: inline-block; transition: 0.2s; }
.tag-item:hover { background: #ffeded; border-color: #ff4d4d; color: #ff4d4d; }
.tag-item.tag-selected { background: #ff4d4d; color: white; border-color: #ff4d4d; }
.transfer-controls { display: flex; flex-direction: column; justify-content: center; gap: 10px; padding-top: 30px; flex-shrink: 0; }
.move-btn { width: 140px; padding: 10px 10px; cursor: pointer; font-weight: bold; font-size: 13px; border-radius: 6px; border: 1px solid #ddd; background: #f8f9fa; transition: 0.2s; text-align: center; }
.move-btn:hover { background: #007bff; color: white; border-color: #007bff; }
.save-btn { width: 100%; margin-top: 25px; padding: 18px; background: #007bff; color: white; border: none; border-radius: 10px; font-size: 16px; font-weight: bold; cursor: pointer; transition: 0.2s; }
.save-btn:hover { background: #0056b3; transform: translateY(-2px); }
.toggle-row { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; }
.toggle-label-text { font-size: 15px; font-weight: 600; color: #444; }
.switch { position: relative; display: inline-block; width: 54px; height: 28px; margin: 0; flex-shrink: 0; }
.switch input { opacity: 0; width: 0; height: 0; }
.slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #ccc; transition: .4s; border-radius: 34px; }
.slider:before { position: absolute; content: ""; height: 20px; width: 20px; left: 4px; bottom: 4px; background-color: white; transition: .4s; border-radius: 50%; box-shadow: 0 2px 4px rgba(0,0,0,0.2); }
input:checked + .slider { background-color: #ff4d4d; }
input:checked + .slider:before { transform: translateX(26px); }
</style>

<script>
function switchAuthTab(tabName) {
    if (tabName === 'login') {
        document.getElementById('form-login').style.display = 'block';
        document.getElementById('form-register').style.display = 'none';
        document.getElementById('tab-login').classList.add('active');
        document.getElementById('tab-register').classList.remove('active');
    } else {
        document.getElementById('form-login').style.display = 'none';
        document.getElementById('form-register').style.display = 'block';
        document.getElementById('tab-register').classList.add('active');
        document.getElementById('tab-login').classList.remove('active');
    }
}

document.addEventListener("DOMContentLoaded", function() {
    const modal = document.getElementById("allergen-modal-overlay");
    const closeM = () => { modal.style.display = "none"; };
    
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('allergen_updated') === '1') {
        modal.style.display = "block";
        const newUrl = window.location.protocol + "//" + window.location.host + window.location.pathname;
        window.history.replaceState({}, document.title, newUrl);
    }
    
    document.getElementById("allergen-trigger-icon").onclick = () => { modal.style.display = "block"; };
    document.getElementById("close-allergen-modal").onclick = closeM;
    window.addEventListener("pageshow", function(event) { if (event.persisted) modal.style.display = "none"; });

    const availBox = document.getElementById("available-items-box");
    if (availBox) {
        const displayBox = document.getElementById("structured-selected-display");
        const hiddenContainer = document.getElementById("hidden-inputs-container");
        let savedIdsArray = <?php echo is_user_logged_in() ? json_encode(array_map('intval', $saved_ids)) : '[]'; ?>;
        let selectedData = savedIdsArray.map(id => {
            const el = availBox.querySelector(`.list-item-full[data-id="${id}"]`);
            return el ? { id: id, name: el.dataset.name, group: el.dataset.group } : null;
        }).filter(x => x);
        let itemsToRemove = new Set();

        const renderStructured = () => {
            displayBox.innerHTML = ""; hiddenContainer.innerHTML = "";
            if (selectedData.length === 0) { displayBox.innerHTML = '<span style="color:#999">No restrictions selected.</span>'; return; }
            const grouped = selectedData.reduce((acc, item) => {
                if (!acc[item.group]) acc[item.group] = [];
                acc[item.group].push(item); return acc;
            }, {});
            Object.keys(grouped).sort().forEach(groupName => {
                const row = document.createElement("div"); row.className = "group-row";
                row.innerHTML = `<span class="group-title">${groupName}: </span>`;
                grouped[groupName].sort((a,b) => a.name.localeCompare(b.name)).forEach((item, idx) => {
                    const tag = document.createElement("span");
                    tag.className = "tag-item" + (itemsToRemove.has(item.id) ? " tag-selected" : "");
                    tag.innerText = item.name;
                    tag.onclick = () => { itemsToRemove.has(item.id) ? itemsToRemove.delete(item.id) : itemsToRemove.add(item.id); renderStructured(); };
                    tag.ondblclick = () => removeItem(item.id);
                    row.appendChild(tag);
                    if (idx < grouped[groupName].length - 1) row.appendChild(document.createTextNode(", "));
                    const input = document.createElement("input"); 
                    input.type = "hidden"; input.name = "allergen_ids[]"; input.value = item.id;
                    hiddenContainer.appendChild(input);
                });
                displayBox.appendChild(row);
            });
        };

        availBox.onclick = (e) => { if(e.target.classList.contains('list-item-full')) e.target.classList.toggle('selected-for-add'); };
        availBox.ondblclick = (e) => {
            if(e.target.classList.contains('list-item-full')) {
                const id = parseInt(e.target.dataset.id);
                if(!selectedData.find(x => x.id === id)) {
                    selectedData.push({id, name: e.target.dataset.name, group: e.target.dataset.group});
                    e.target.style.display = "none"; e.target.classList.remove('selected-for-add'); renderStructured();
                }
            }
        };

        document.getElementById("btn-add-allg").onclick = () => {
            availBox.querySelectorAll('.selected-for-add').forEach(el => {
                const id = parseInt(el.dataset.id);
                if(!selectedData.find(x => x.id === id)) {
                    selectedData.push({id, name: el.dataset.name, group: el.dataset.group});
                    el.style.display = "none"; el.classList.remove('selected-for-add');
                }
            });
            renderStructured();
        };

        document.getElementById("btn-add-all-allg").onclick = () => {
            availBox.querySelectorAll('.list-item-full').forEach(el => {
                if (el.style.display !== "none") {
                    const id = parseInt(el.dataset.id);
                    if(!selectedData.find(x => x.id === id)) {
                        selectedData.push({id, name: el.dataset.name, group: el.dataset.group});
                        el.style.display = "none"; el.classList.remove('selected-for-add');
                    }
                }
            });
            renderStructured();
        };

        const removeItem = (id) => {
            selectedData = selectedData.filter(x => x.id !== id); itemsToRemove.delete(id);
            const el = availBox.querySelector(`.list-item-full[data-id="${id}"]`);
            if(el) { el.style.display = "block"; filterFn(); }
            renderStructured();
        };

        document.getElementById("btn-remove-allg").onclick = () => {
            itemsToRemove.forEach(id => removeItem(id));
        };

        document.getElementById("btn-remove-all-allg").onclick = () => {
            const allIds = selectedData.map(x => x.id);
            allIds.forEach(id => removeItem(id));
        };

        const filterFn = () => {
            let rawQuery = document.getElementById("allergen-search").value.toLowerCase().trim();
            let q = rawQuery;
            if (q.endsWith('es') && q.length > 3) {
                q = q.slice(0, -2);
            } else if (q.endsWith('s') && q.length > 2) {
                q = q.slice(0, -1);
            }

            const cat = document.getElementById("group-filter").value;

            availBox.querySelectorAll('.list-item-full').forEach(el => {
                if (selectedData.find(x => x.id === parseInt(el.dataset.id))) {
                    el.style.display = "none";
                    return;
                }
                const text = el.innerText.toLowerCase();
                const aliases = (el.dataset.aliases || "").toLowerCase();
                const mS = text.includes(rawQuery) || text.includes(q) || aliases.includes(rawQuery) || aliases.includes(q);
                const mC = (cat === 'all' || el.dataset.group === cat);
                el.style.display = (mS && mC) ? "block" : "none";
            });
        };

        document.getElementById("allergen-search").oninput = filterFn;
        document.getElementById("group-filter").onchange = filterFn;

        renderStructured();
    }
});
</script>
<?php
}

add_action('init', function() {
    if (isset($_POST['save_db_allergens']) && is_user_logged_in()) {
        global $wpdb;
        $user_id = get_current_user_id();
        
        $highlight = isset($_POST['prot_highlight']) ? '1' : '0';
        $strict = isset($_POST['prot_strict']) ? '1' : '0';
        
        update_user_meta($user_id, 'allergen_setting_highlight', $highlight);
        update_user_meta($user_id, 'allergen_setting_strict', $strict);

        $wpdb->delete('user_allergens_map', ['user_id' => $user_id]);

        if (!empty($_POST['allergen_ids'])) {
            foreach ($_POST['allergen_ids'] as $id) {
                $wpdb->insert('user_allergens_map', ['user_id' => $user_id, 'allergen_id' => intval($id)]);
            }
        }
        $redirect_url = wp_get_referer() ? wp_get_referer() : home_url();
        $redirect_url = add_query_arg('allergen_updated', '1', $redirect_url);
        wp_safe_redirect($redirect_url);
        exit;
    }
});

// =========================================================================
// 4. RECIPE POST TYPE & CONTENT FILTER (UNIVERSAL & STABLE)
// =========================================================================

add_action('init', 'allergen_register_recipe_post_type');
function allergen_register_recipe_post_type() {
    register_post_type('recipe', array(
        'labels'      => array('name' => 'Recipes', 'singular_name' => 'Recipe'),
        'public'      => true,
        'has_archive' => true,
        'menu_icon'   => 'dashicons-carrot',
        'supports'    => array('title', 'editor', 'thumbnail', 'excerpt'),
    ));
}

// Привязываемся к выводу контента и анонсов с базовым приоритетом
add_filter('the_content', 'allergen_engine_filter_recipes', 10);
add_filter('the_excerpt', 'allergen_engine_filter_recipes', 10);

function allergen_engine_filter_recipes($content) {
    // 1. Обязательные проверки: пользователь вошел и мы находимся строго внутри цикла вывода записей
    if (!is_user_logged_in() || !in_the_loop()) {
        return $content;
    }

    // 2. Фильтруем только рецепты (recipe) и обычные записи (post)
    if (!in_array(get_post_type(), array('recipe', 'post'), true)) {
        return $content;
    }

    $user_id = get_current_user_id();
    $forbidden_words = allergen_get_user_forbidden_words($user_id);
    
    if (empty($forbidden_words)) {
        return $content;
    }

    // Проверяем наличие аллергенов в тексте
    $found_allergen = allergen_find_in_text($content, $forbidden_words);
    if (!$found_allergen) {
        return $content;
    }

    $is_strict    = get_user_meta($user_id, 'allergen_setting_strict', true) === '1';
    $is_highlight = get_user_meta($user_id, 'allergen_setting_highlight', true) === '1';

    // --- ШАГ 1: ПОДСВЕТКА СЛОВ (Выполняется всегда при нахождении совпадения) ---
    usort($forbidden_words, function($a, $b) {
        return mb_strlen($b) - mb_strlen($a);
    });

    foreach ($forbidden_words as $word) {
        if (mb_strlen($word) > 2) {
            $pattern = '/(\p{L}*' . preg_quote($word, '/') . '\p{L}*)(?![^<]*>)/iu';
            $replacement = '<span class="allergen-warning" style="color: #ff4d4d; font-weight: bold; text-decoration: underline; cursor: help;">$1</span>';
            $content = preg_replace($pattern, $replacement, $content);
        }
    }

    // --- ШАГ 2: ОТОБРАЖЕНИЕ БАННЕРОВ И БЛОКИРОВОК ---
    $is_single = is_singular(array('recipe', 'post'));

    // Режим Strict Mode (Полная блокировка)
    if ($is_strict) {
        if ($is_single) {
            // Полноразмерный красивый блок на странице самого рецепта
            return '<div class="allergen-server-block" style="border: 2px solid #ff4d4d; border-radius: 10px; padding: 30px; text-align: center; background: #fff0f0; margin: 20px 0; clear: both; width: 100%; box-sizing: border-box; display: block;">
                        <h3 style="color: #ff4d4d; margin-top: 0; font-size: 24px; font-weight: bold; display: block;">⚠️ STRICT MODE ACTIVE</h3>
                        <p style="font-size: 16px; color: #333; margin: 10px 0 0 0; display: block;">This recipe contains ingredients that are unsafe for your profile: <b>' . esc_html(ucfirst($found_allergen)) . '</b></p>
                    </div>';
        } else {
            // Компактная плашка для главной страницы (чтобы контент не исчезал полностью и не ломал сетку темы)
            return '<div class="allergen-server-block" style="border: 1px solid #ff4d4d; border-radius: 6px; padding: 10px; text-align: center; background: #fff0f0; margin: 10px 0; font-size: 14px; color: #ff4d4d; font-weight: bold; width: 100%; box-sizing: border-box; display: block;">
                        ⚠️ Blocked: Contains ' . esc_html(ucfirst($found_allergen)) . '
                    </div>';
        }
    }

    // Режим Highlight Mode (Предупреждающий баннер сверху текста)
    if ($is_highlight) {
        if ($is_single) {
            $warning_banner = '<div style="background: #fff0f0; color: #d9534f; padding: 15px; border-left: 5px solid #d9534f; margin-bottom: 20px; font-weight: bold; border-radius: 5px; clear: both; width: 100%; box-sizing: border-box; display: block;">
                                   ⚠️ WARNING: Contains allergens (e.g. ' . esc_html(ucfirst($found_allergen)) . ')
                               </div>';
            return $warning_banner . $content;
        } else {
            $warning_label = '<div style="color: #d9534f; font-weight: bold; font-size: 14px; margin-bottom: 8px; display: block; clear: both;">⚠️ Contains allergens!</div>';
            return $warning_label . $content;
        }
    }

    // Если режимы плашек выключены, просто отдаем текст с подсвеченными словами
    return $content;
}

// ==========================================
// 5. ADMIN MENU & PAGES
// ==========================================
add_action('admin_menu', 'allergen_admin_menu_pro');
function allergen_admin_menu_pro() {
    add_menu_page('Allergens', 'Allergens', 'manage_options', 'allergen-main', 'render_allergen_page_pro', 'dashicons-shield-alt', 6);
    add_submenu_page('allergen-main', 'Groups', 'Allergen Groups', 'manage_options', 'allergen-groups', 'render_groups_page_pro');
}

function render_allergen_page_pro() {
    global $wpdb;
    $tab = isset($_GET['tab']) ? $_GET['tab'] : 'list';
    $table = 'allergens';
    $alias_table = 'allergen_aliases'; 

    if (isset($_GET['del'])) {
        $wpdb->delete($table, ['allergen_id' => intval($_GET['del'])]);
        $wpdb->delete($alias_table, ['allergen_id' => intval($_GET['del'])]); 
        echo '<div class="updated"><p>Successfully deleted!</p></div>';
    }

    if (isset($_POST['save_allergen'])) {
        $allergen_data = [
            'allergen_name' => sanitize_text_field($_POST['name']),
            'group_id' => intval($_POST['group_id'])
        ];

        if (!empty($_POST['id'])) {
            $allergen_id = intval($_POST['id']);
            $wpdb->update($table, $allergen_data, ['allergen_id' => $allergen_id]);
        } else {
            $wpdb->insert($table, $allergen_data);
            $allergen_id = $wpdb->insert_id;
        }

        $wpdb->delete($alias_table, ['allergen_id' => $allergen_id]); 
        if (!empty($_POST['aliases'])) {
            $aliases = explode(',', $_POST['aliases']);
            foreach ($aliases as $alias) {
                $alias = trim(sanitize_text_field($alias));
                if ($alias) {
                    $wpdb->insert($alias_table, ['allergen_id' => $allergen_id, 'alias_name' => $alias]);
                }
            }
        }
        echo '<script>window.location.href="admin.php?page=allergen-main&tab=list";</script>';
    }

    echo '<div class="wrap"><h1>Allergen Management</h1>';
    
    echo '<h2 class="nav-tab-wrapper">
        <a href="?page=allergen-main&tab=list" class="nav-tab '.($tab=='list'?'nav-tab-active':'').'">Allergen List</a>
        <a href="?page=allergen-main&tab=add" class="nav-tab '.($tab=='add'?'nav-tab-active':'').'">+ Add New Allergen</a>
    </h2>';

    if ($tab == 'list') {
        $items = $wpdb->get_results("
            SELECT a.*, g.group_name, GROUP_CONCAT(al.alias_name SEPARATOR ', ') as synonyms 
            FROM $table a 
            LEFT JOIN allergen_groups g ON a.group_id = g.group_id 
            LEFT JOIN $alias_table al ON a.allergen_id = al.allergen_id
            GROUP BY a.allergen_id
        ");

        echo '<table class="wp-list-table widefat fixed striped" style="margin-top:20px;">
                <thead><tr><th>Name</th><th>Synonyms (Technical)</th><th>Group</th><th>Actions</th></tr></thead><tbody>';
        foreach ($items as $item) {
            echo "<tr>
                <td><strong>{$item->allergen_name}</strong></td>
                <td><small style='color:#666;'>".($item->synonyms ? $item->synonyms : '—')."</small></td>
                <td>".($item->group_name ? $item->group_name : '—')."</td>
                <td>
                    <a href='?page=allergen-main&tab=add&edit={$item->allergen_id}'>Edit</a> | 
                    <a href='?page=allergen-main&del={$item->allergen_id}' style='color:red;' onclick='return confirm(\"Are you sure you want to delete this?\")'>Delete</a>
                </td></tr>";
        }
        echo '</tbody></table>';

        echo '<div style="clear:both; margin-top: 40px;"></div>';

        // ==========================================
        // SMART CHECK & AUTO-FIX (OPEN FOOD FACTS)
        // ==========================================
        echo '<div style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04); border-radius: 4px; margin-bottom: 20px;">';
        echo '<h2 style="margin-top:0;">Smart Synonym Check & Auto-Correction</h2>';
        echo '<p>Cross-reference your synonyms with the Open Food Facts database. Exact typos (e.g. Peanot -> Peanut) <strong>will be fixed automatically</strong>.</p>';

        if (isset($_POST['run_api_check'])) {
            $query_aliases = "SELECT alias_id, alias_name FROM $alias_table WHERE alias_name IS NOT NULL AND alias_name != ''";
            $results_aliases = $wpdb->get_results($query_aliases);

            if ($results_aliases) {
                $all_words_array = [];
                foreach ($results_aliases as $row) {
                    $words_in_row = explode(',', $row->alias_name);
                    foreach ($words_in_row as $w) if (trim($w) !== '') $all_words_array[] = trim($w);
                }
                
                $all_words_string = implode(',', array_filter($all_words_array));
                $api_result = check_allergens_via_open_food_facts($all_words_string);

                if (is_string($api_result)) {
                    echo "<div style='background: #fff; padding: 15px; border-left: 4px solid #dc3232; margin-bottom: 20px;'><strong>❌ Error:</strong> " . esc_html($api_result) . "</div>";
                } else {
                    if (!empty($api_result['corrected'])) {
                        foreach ($api_result['corrected'] as $old_word => $new_word) {
                            $wpdb->update($alias_table, ['alias_name' => strtolower($new_word)], ['alias_name' => strtolower($old_word)]);
                        }
                    }

                    echo "<div style='background: #f6fdf6; padding: 15px; border-left: 4px solid #46b450; margin-bottom: 10px;'><strong>✅ Correct words:</strong><br>" . esc_html(implode(', ', $api_result['found'])) . "</div>";

                    if (!empty($api_result['corrected'])) {
                        echo "<div style='background: #fffaf0; padding: 15px; border-left: 4px solid #ffba00; margin-bottom: 10px;'><strong>⚡ AUTOMATICALLY FIXED IN DB:</strong><br>";
                        foreach ($api_result['corrected'] as $old => $new) echo "<span style='text-decoration:line-through; color:#888;'>$old</span> ➔ <strong>$new</strong><br>";
                        echo "</div>";
                    }

                    echo "<div style='background: #fdf6f6; padding: 15px; border-left: 4px solid #dc3232; margin-bottom: 20px;'><strong>❌ NOT found (Check manually):</strong><br>";
                    echo !empty($api_result['not_found']) ? esc_html(implode(', ', $api_result['not_found'])) : "None found!";
                    echo "</div>";
                }
            } else {
                echo '<p>There are no synonyms in the database to check yet.</p>';
            }
        }

        echo '<form method="post"><input type="hidden" name="run_api_check" value="1"><input type="submit" class="button button-primary" value="Check and Fix Typos"></form>';
        echo '</div>';


        // ==========================================
        // DUAL-SOURCE IMPORT (ALLERGENS + SYNONYMS)
        // ==========================================
        echo '<div style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04); border-radius: 4px; margin-bottom: 20px;">';
        echo '<h2 style="margin-top:0;">🌐 Smart Multi-Import</h2>';
        echo '<p>Downloads the base allergens and attaches your custom synonyms database.</p>';

        if (isset($_POST['run_auto_import'])) {
            set_time_limit(300); 
            global $wpdb;
            $wpdb->show_errors();
            
            $api_url_allergens = 'https://world.openfoodfacts.org/data/taxonomies/allergens.json';
            $api_url_synonyms = 'https://gist.githubusercontent.com/N1san-gif/f52ef75e4cd0e43c182351683686a55e/raw/3b6032d250811729b1f3142883ef1c3c7c634dca/gistfile1.txt'; 

            $added_count = 0; 
            $synonyms_count = 0;

            $smart_categories = [
                'Dairy & Milk'     => ['milk', 'dairy', 'cheese', 'butter', 'whey', 'lactose', 'casein'],
                'Nuts & Peanuts'   => ['nut', 'almond', 'pecan', 'cashew', 'pistachio', 'macadamia', 'walnut', 'peanut'],
                'Seafood & Fish'   => ['fish', 'crustacean', 'mollusc', 'shrimp', 'crab', 'salmon', 'oyster', 'shellfish', 'squid', 'caviar'],
                'Gluten & Cereals' => ['wheat', 'gluten', 'barley', 'rye', 'oat', 'spelt', 'cereal', 'kamut'],
                'Eggs'             => ['egg'],
                'Soy'              => ['soy', 'soybean'],
                'Mustard & Celery' => ['mustard', 'celery'],
                'Fruits'           => ['apple', 'banana', 'orange', 'kiwi', 'peach', 'fruit'],
                'Meat & Poultry'   => ['beef', 'pork', 'chicken', 'meat', 'poultry', 'gelatin'],
                'Seeds & Lupin'    => ['sesame', 'lupin', 'seed'],
                'Mushrooms & Roots'=> ['matsutake', 'yamaimo', 'mushroom'],
                'Sulphites'        => ['sulphur', 'sulfite', 'sulphite'],
                'Other / Uncategorized' => []
            ];

            $category_ids = [];
            foreach ($smart_categories as $cat_name => $keywords) {
                $c_id = $wpdb->get_var($wpdb->prepare("SELECT group_id FROM allergen_groups WHERE group_name = %s", $cat_name));
                if (!$c_id) {
                    $wpdb->insert('allergen_groups', ['group_name' => $cat_name, 'description' => 'Auto-category']);
                    $c_id = $wpdb->insert_id;
                }
                $category_ids[$cat_name] = $c_id;
            }

            $response_1 = wp_remote_get($api_url_allergens, array('timeout' => 30));
            if (!is_wp_error($response_1)) {
                $allergens_data = json_decode(wp_remote_retrieve_body($response_1), true);
                if ($allergens_data) {
                    foreach ($allergens_data as $key => $info) {
                        if (isset($info['name']['en'])) {
                            $main_name = sanitize_text_field(ucfirst(trim($info['name']['en'])));
                            $name_lower = strtolower($main_name);
                            
                            $exists_id = $wpdb->get_var($wpdb->prepare("SELECT allergen_id FROM allergens WHERE allergen_name = %s", $main_name));
                            if (!$exists_id) {
                                $assigned_group_id = $category_ids['Other / Uncategorized'];
                                $found_cat = false;
                                foreach ($smart_categories as $cat_name => $keywords) {
                                    if ($cat_name === 'Other / Uncategorized') continue;
                                    foreach ($keywords as $kw) {
                                        if (strpos($name_lower, $kw) !== false) {
                                            $assigned_group_id = $category_ids[$cat_name];
                                            $found_cat = true;
                                            break;
                                        }
                                    }
                                    if ($found_cat) break;
                                }

                                $wpdb->insert('allergens', ['allergen_name' => $main_name, 'group_id' => $assigned_group_id]);
                                if ($wpdb->insert_id) $added_count++;
                            }
                        }
                    }
                }
            }

            if (strpos($api_url_synonyms, 'http') === 0) {
                $response_2 = wp_remote_get($api_url_synonyms, array('timeout' => 30));
                if (!is_wp_error($response_2)) {
                    $synonyms_data = json_decode(wp_remote_retrieve_body($response_2), true);
                    if ($synonyms_data && is_array($synonyms_data)) {
                        foreach ($synonyms_data as $base_allergen => $synonym_list) {
                            $base_allergen_clean = sanitize_text_field(ucfirst(trim($base_allergen)));
                            $allergen_id = $wpdb->get_var($wpdb->prepare("SELECT allergen_id FROM allergens WHERE allergen_name = %s", $base_allergen_clean));
                            
                            if ($allergen_id && is_array($synonym_list)) {
                                foreach ($synonym_list as $syn) {
                                    $clean_syn = sanitize_text_field(strtolower(trim($syn)));
                                    $syn_exists = $wpdb->get_var($wpdb->prepare("SELECT alias_id FROM allergen_aliases WHERE allergen_id = %d AND alias_name = %s", $allergen_id, $clean_syn));
                                    
                                    if (!$syn_exists && !empty($clean_syn)) {
                                        $wpdb->insert('allergen_aliases', ['allergen_id' => $allergen_id, 'alias_name' => $clean_syn]);
                                        $synonyms_count++;
                                    }
                                }
                            }
                        }
                    }
                } else {
                    echo "<div style='color:#ffba00;'>⚠️ Failed to download synonyms. Please check your GitHub link.</div>";
                }
            }

            if ($added_count > 0 || $synonyms_count > 0) {
                echo "<div style='background: #f6fdf6; padding: 15px; border-left: 4px solid #46b450; margin-bottom: 20px; margin-top: 15px;'><strong>✅ Success!</strong><br>New allergens added: <b>$added_count</b>.<br>Synonyms loaded: <b>$synonyms_count</b>.</div>";
                echo "<script>setTimeout(function(){ window.location.href = 'admin.php?page=allergen-main&tab=list'; }, 2000);</script>";
            } else {
                echo "<div style='background: #fffaf0; padding: 15px; border-left: 4px solid #ffba00; margin-bottom: 20px; margin-top: 15px;'><strong>⚠️ No new data.</strong> Everything might already be imported.</div>";
            }
            $wpdb->hide_errors();
        }

        echo '<form method="post"><input type="hidden" name="run_auto_import" value="1"><input type="submit" class="button button-primary" value="⬇️ Run Multi-Import"></form>';
        echo '</div>';

    } else {
        $edit_id = isset($_GET['edit']) ? intval($_GET['edit']) : 0;
        $row = $edit_id ? $wpdb->get_row("SELECT * FROM $table WHERE allergen_id = $edit_id") : null;
        $existing_aliases = $edit_id ? $wpdb->get_col("SELECT alias_name FROM $alias_table WHERE allergen_id = $edit_id") : [];
        $groups = $wpdb->get_results("SELECT * FROM allergen_groups");
        ?>
        <div class="card" style="max-width: 600px; margin-top: 20px;">
            <form method="post">
                <input type="hidden" name="id" value="<?php echo $edit_id; ?>">
                <table class="form-table">
                    <tr><th>Main Name:</th><td><input type="text" name="name" value="<?php echo $row?$row->allergen_name:''; ?>" required class="regular-text"></td></tr>
                    <tr><th>Synonyms (comma separated):</th><td><textarea name="aliases" rows="3" class="large-text"><?php echo implode(', ', $existing_aliases); ?></textarea></td></tr>
                    <tr><th>Group:</th><td>
                        <select name="group_id" style="width: 25em;">
                            <option value="0">No Group</option>
                            <?php foreach($groups as $g) echo "<option value='{$g->group_id}' ".($row && $row->group_id==$g->group_id?'selected':'').">{$g->group_name}</option>"; ?>
                        </select>
                    </td></tr>
                </table>
                <p><input type="submit" name="save_allergen" class="button button-primary" value="Save Allergen & Synonyms"></p>
            </form>
        </div>
        <?php
    }
    echo '</div>'; 
}

function render_groups_page_pro() {
    global $wpdb;
    $table = 'allergen_groups';
    
    if (isset($_POST['save_group'])) {
        $wpdb->insert($table, [
            'group_name' => sanitize_text_field($_POST['gname']), 
            'description' => sanitize_textarea_field($_POST['gdesc'])
        ]);
    }

    echo '<div class="wrap"><h1>Allergen Groups</h1>';
    ?>
    <div style="display: flex; gap: 20px; margin-top: 20px;">
        <div class="card" style="flex: 1; height: fit-content;">
            <h3>Create New Group</h3>
            <form method="post">
                <p><label>Group Name:</label><br><input type="text" name="gname" required style="width:100%"></p>
                <p><label>Description:</label><br><textarea name="gdesc" style="width:100%"></textarea></p>
                <p><input type="submit" name="save_group" class="button button-primary" value="Create Group"></p>
            </form>
        </div>
        <div style="flex: 2;">
            <table class="wp-list-table widefat fixed striped">
                <thead><tr><th>Group Name</th><th>Description</th></tr></thead>
                <tbody>
                    <?php
                    $groups = $wpdb->get_results("SELECT * FROM $table");
                    foreach ($groups as $g) echo "<tr><td><strong>{$g->group_name}</strong></td><td>{$g->description}</td></tr>";
                    ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
    echo '</div>';
}

// ==========================================
// 6. OPEN FOOD FACTS API LOGIC
// ==========================================
function check_allergens_via_open_food_facts($user_input_string) {
    $words_to_check = array_map('trim', explode(',', $user_input_string));
    $api_url = 'https://world.openfoodfacts.org/data/taxonomies/allergens.json';
    $response = wp_remote_get($api_url, array('timeout' => 15));
    
    if (is_wp_error($response)) {
        return "API connection error: " . $response->get_error_message();
    }
    
    $body = wp_remote_retrieve_body($response);
    $allergens_data = json_decode($body, true);
    
    if (!$allergens_data) return "API data decoding error.";

    $all_known_synonyms = [];
    foreach ($allergens_data as $allergen_info) {
        if (isset($allergen_info['name']['en'])) $all_known_synonyms[] = strtolower($allergen_info['name']['en']);
        if (isset($allergen_info['synonyms']['en'])) {
            foreach ($allergen_info['synonyms']['en'] as $synonym) {
                $all_known_synonyms[] = strtolower($synonym);
            }
        }
    }
    
    $results = ['found' => [], 'corrected' => [], 'not_found' => []];
    
    foreach ($words_to_check as $word) {
        if (empty($word)) continue;
        
        $word_lower = strtolower($word);
        
        if (in_array($word_lower, $all_known_synonyms)) {
            $results['found'][] = $word;
            continue;
        } 
        
        $closest_word = '';
        $shortest_distance = -1;
        
        foreach ($all_known_synonyms as $known_synonym) {
            $lev = levenshtein($word_lower, $known_synonym);
            
            if ($lev <= $shortest_distance || $shortest_distance < 0) {
                $closest_word  = $known_synonym;
                $shortest_distance = $lev;
            }
        }
        
        if ($shortest_distance > 0 && $shortest_distance <= 2) {
            $results['corrected'][$word] = ucfirst($closest_word); 
        } else {
            $results['not_found'][] = $word;
        }
    }
    return $results;
}

// ==========================================
// 7. FIX LOCALHOST SMTP (FOR XAMPP EMAILS)
// ==========================================
add_filter( 'wp_mail_smtp_custom_options', function( $phpmailer ) {
    $phpmailer->SMTPOptions = array(
        'ssl' => array(
            'verify_peer'       => false,
            'verify_peer_name'  => false,
            'allow_self_signed' => true
        )
    );
    return $phpmailer;
} );

// ==========================================
// 8. RECIPES SHORTCODE [allergen_recipes]
// ==========================================
add_shortcode('allergen_recipes', 'allergen_display_recipes_shortcode');

function allergen_display_recipes_shortcode($atts) {
    $args = array(
        'post_type'      => 'recipe',
        'posts_per_page' => -1,
        'post_status'    => 'publish'
    );

    $recipes_query = new WP_Query($args);
    
    $output = '<div class="allergen-recipe-grid" style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-top: 20px;">';

    $user_id = get_current_user_id();
    $forbidden_words = [];
    $is_strict = false;
    $is_highlight = false;

    if (is_user_logged_in()) {
        $forbidden_words = allergen_get_user_forbidden_words($user_id);
        $is_strict = get_user_meta($user_id, 'allergen_setting_strict', true) === '1';
        $is_highlight = get_user_meta($user_id, 'allergen_setting_highlight', true) === '1';
    }

    if ($recipes_query->have_posts()) {
        while ($recipes_query->have_posts()) {
            $recipes_query->the_post();
            
            $title = get_the_title();
            $link = get_permalink();
            $excerpt = wp_trim_words(get_the_excerpt(), 15, '...');
            $full_content = get_the_content(); 
            $img_url = get_the_post_thumbnail_url(get_the_ID(), 'medium');
            
            $found_allergen = null;
            if (!empty($forbidden_words)) {
                $text_to_check = $title . ' ' . $full_content;
                $found_allergen = allergen_find_in_text($text_to_check, $forbidden_words);
            }

            if ($found_allergen && $is_strict) {
                $output .= '<div class="recipe-card blocked-card" style="border: 2px dashed #ff4d4d; border-radius: 8px; background: #fff0f0; padding: 20px; display:flex; flex-direction:column; align-items:center; justify-content:center; text-align: center; color: #d9534f; opacity: 0.8; height: 100%; min-height: 280px;">';
                $output .= '<span style="font-size: 30px; margin-bottom: 10px;">🚫</span>';
                $output .= '<strong>Blocked by Profile</strong><br><small style="color: #666; margin-top: 5px;">Contains: <b style="color:#d9534f;">' . esc_html(ucfirst($found_allergen)) . '</b></small>';
                $output .= '</div>';
                continue; 
            }

            $card_style = 'border: 1px solid #eaeaea; border-radius: 8px; background: #fff; box-shadow: 0 4px 6px rgba(0,0,0,0.05); transition: transform 0.2s; position: relative; display: flex; flex-direction: column; height: 100%;';
            $warning_badge = '';
            
            if ($found_allergen && $is_highlight) {
                $card_style = 'border: 3px solid #ff4d4d; border-radius: 8px; background: #fffdfd; box-shadow: 0 4px 15px rgba(255,77,77,0.15); transition: transform 0.2s; position: relative; display: flex; flex-direction: column; height: 100%;';
                $warning_badge = '<div style="position: absolute; top: 10px; right: 10px; background: #ff4d4d; color: white; padding: 5px 10px; border-radius: 6px; font-size: 12px; font-weight: bold; z-index: 2; box-shadow: 0 2px 5px rgba(0,0,0,0.2);">⚠️ ' . esc_html(ucfirst($found_allergen)) . '</div>';
            }
            
            $img_html = $img_url ? '<img src="' . esc_url($img_url) . '" alt="' . esc_attr($title) . '" style="width: 100%; height: 180px; object-fit: cover; border-radius: 5px 5px 0 0; margin-bottom: 0;">' : '<div style="width: 100%; height: 180px; background: #f1f3f5; border-radius: 5px 5px 0 0; display: flex; align-items: center; justify-content: center; color: #aaa;">No image</div>';

            $output .= '<div class="recipe-card" style="' . $card_style . '">';
            $output .= $warning_badge;
            $output .= '<a href="' . esc_url($link) . '" style="text-decoration: none; color: inherit; flex-grow: 1; display: flex; flex-direction: column;">';
            $output .= $img_html;
            $output .= '<div style="padding: 15px; flex-grow: 1;">';
            $output .= '<h3 style="margin: 0 0 10px 0; font-size: 18px; color: #333;">' . esc_html($title) . '</h3>';
            $output .= '<p style="margin: 0; font-size: 14px; color: #666; line-height: 1.5;">' . esc_html($excerpt) . '</p>';
            $output .= '</div>';
            $output .= '</a>';
            $output .= '</div>';
        }
        wp_reset_postdata();
    } else {
        $output .= '<p style="grid-column: 1 / -1; text-align: center; color: #777; padding: 40px; background: #f9f9f9; border-radius: 10px;">Рецепты пока не добавлены.</p>';
    }

    $output .= '</div>';
    $output .= '<style>.recipe-card:hover { transform: translateY(-5px); box-shadow: 0 8px 20px rgba(0,0,0,0.12) !important; }</style>';

    return $output;
}

// ==========================================
// 9. PROTECTED WIKIPEDIA API INTEGRATION
// ==========================================
add_action('wp_enqueue_scripts', 'allergen_wiki_tooltip_assets');
function allergen_wiki_tooltip_assets() {
    if (!is_user_logged_in()) return;

    wp_enqueue_script('jquery');
    
    $custom_css = "
        .wiki-tooltip {
            position: absolute;
            background: #fff;
            border: 1px solid #ccd0d4;
            border-radius: 6px;
            padding: 15px;
            width: 300px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            z-index: 9999;
            display: none;
            font-family: sans-serif;
            font-size: 13px;
            color: #333;
        }
        .wiki-tooltip h4 { margin-top: 0; margin-bottom: 8px; font-size: 15px; color: #2271b1; }
        .wiki-tooltip p { margin: 0; line-height: 1.5; }
        .wiki-tooltip .wiki-loader { text-align: center; color: #888; font-style: italic; }
    ";
    wp_add_inline_style('wp-block-library', $custom_css);
    
    $ajax_url = admin_url('admin-ajax.php');
    $nonce = wp_create_nonce('wiki_nonce');
    
    $custom_js = "
        jQuery(document).ready(function($) {
            $('body').append('<div id=\"wiki-tooltip\" class=\"wiki-tooltip\"></div>');
            var tooltip = $('#wiki-tooltip');
            var currentRequest = null;

            $('.allergen-warning').hover(
                function(e) {
                    var rawText = $(this).text();
                    var term = rawText.replace(/[🚨⚠️]/g, '').trim(); 
                    
                    tooltip.html('<div class=\"wiki-loader\">⏳ Загрузка из Wikipedia...</div>');
                    tooltip.css({ top: e.pageY + 15 + 'px', left: e.pageX + 15 + 'px' }).fadeIn(200);

                    currentRequest = $.ajax({
                        url: '{$ajax_url}',
                        type: 'POST',
                        data: {
                            action: 'get_wiki_info',
                            term: term,
                            security: '{$nonce}'
                        },
                        success: function(response) {
                            if (response.success) {
                                tooltip.html('<h4>' + response.data.title + '</h4><p>' + response.data.extract + '</p>');
                            } else {
                                tooltip.html('<p>❌ Информация об ингредиенте не найдена.</p>');
                            }
                        }
                    });
                },
                function() {
                    if (currentRequest) { currentRequest.abort(); }
                    tooltip.fadeOut(200);
                }
            );

            $('.allergen-warning').mousemove(function(e) {
                tooltip.css({ top: e.pageY + 15 + 'px', left: e.pageX + 15 + 'px' });
            });
        });
    ";
    wp_add_inline_script('jquery', $custom_js);
}

add_action('wp_ajax_get_wiki_info', 'allergen_fetch_wikipedia_data');
function allergen_fetch_wikipedia_data() {
    check_ajax_referer('wiki_nonce', 'security');

    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'Access Denied. Please log in.']);
    }

    $term = isset($_POST['term']) ? sanitize_text_field($_POST['term']) : '';
    if (empty($term)) wp_send_json_error(['message' => 'No term provided.']);

    $wiki_url = 'https://en.wikipedia.org/api/rest_v1/page/summary/' . urlencode($term);
    
    $response = wp_remote_get($wiki_url, ['timeout' => 5]);

    if (is_wp_error($response)) {
        wp_send_json_error(['message' => 'Wikipedia API connection failed.']);
    }

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    if (isset($data['title']) && isset($data['extract'])) {
        wp_send_json_success([
            'title' => sanitize_text_field($data['title']),
            'extract' => wp_html_excerpt($data['extract'], 250, '...') 
        ]);
    } else {
        wp_send_json_error(['message' => 'Not found on Wikipedia.']);
    }
}
?>