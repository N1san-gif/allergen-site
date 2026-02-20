<?php
/*
Plugin Name: Allergen Profile System Pro (English)
Version: 11.0
Author: Gemini & Yaroslav
*/

// --- DATABASE HOOKS ---
add_action('wp_footer', 'allergen_profile_system_render');

function allergen_profile_system_render() {
    global $wpdb;
    
    // 1. Fetch data from custom tables
    $all_allergens = $wpdb->get_results("
        SELECT a.allergen_id, a.allergen_name, a.group_id, GROUP_CONCAT(al.alias_name) as aliases
        FROM allergens a
        LEFT JOIN allergen_aliases al ON a.allergen_id = al.allergen_id
        GROUP BY a.allergen_id
        ORDER BY a.allergen_name ASC
    ");
    $all_groups = $wpdb->get_results("SELECT group_id, group_name FROM allergen_groups ORDER BY group_name ASC");

    $saved_ids = [];
    if (is_user_logged_in()) {
        $user_id = get_current_user_id();
        $saved_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT allergen_id FROM user_allergens_map WHERE user_id = %d", 
            $user_id
        ));
    }
?>

<div id="allergen-trigger-icon" title="My Allergen Profile">⚠️</div>

<div id="allergen-modal-overlay">
    <div class="allergen-modal-window">
        <span id="close-allergen-modal">&times;</span>

        <div id="modal-content-wrapper">
            <?php if (!is_user_logged_in()) : ?>
                <div id="auth-section" style="text-align: center; padding: 20px;">
                    <h3>Access Restricted</h3>
                    <p>Please log in to manage your allergen profile.</p>
                    <?php wp_login_form(['echo' => true, 'redirect' => $_SERVER['REQUEST_URI']]); ?>
                </div>
            <?php else : ?>
                
                <h3 style="margin-top: 0; color: #333; border-bottom: 2px solid #eee; padding-bottom: 10px;">My Allergen Profile</h3>

                <?php if (isset($_GET['allergen_updated'])) : ?>
                    <div class="allergen-success-msg">
                        ✅ Profile updated!
                    </div>
                <?php endif; ?>

                <div class="filter-row">
                    <div class="filter-group">
                        <label>Search:</label>
                        <input type="text" id="allergen-search" placeholder="Search by name or abbreviation...">
                    </div>
                    <div class="filter-group">
                        <label>Category:</label>
                        <select id="group-filter">
                            <option value="all">All Groups</option>
                            <?php foreach ($all_groups as $g) : ?>
                                <option value="<?php echo $g->group_id; ?>"><?php echo esc_html($g->group_name); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <form method="post" id="allergen-transfer-form">
                    <div class="transfer-container">
                        <div class="transfer-column">
                            <label>Available Items</label>
                            <select id="available-list" size="12" multiple>
                                <?php 
                                foreach ($all_allergens as $a) {
                                    if (!in_array($a->allergen_id, $saved_ids)) {
                                        echo "<option value='{$a->allergen_id}' data-group='{$a->group_id}' data-aliases='".esc_attr($a->aliases)."'>{$a->allergen_name}</option>";
                                    }
                                }
                                ?>
                            </select>
                        </div>

                        <div class="transfer-controls">
                            <button type="button" id="btn-add" title="Move to restrictions">Add &raquo;</button>
                            <button type="button" id="btn-remove" title="Remove from restrictions">&laquo; Remove</button>
                        </div>

                        <div class="transfer-column">
                            <label>My Restrictions</label>
                            <select id="selected-list" name="allergen_ids[]" size="12" multiple>
                                <?php 
                                foreach ($all_allergens as $a) {
                                    if (in_array($a->allergen_id, $saved_ids)) {
                                        echo "<option value='{$a->allergen_id}'>{$a->allergen_name}</option>";
                                    }
                                }
                                ?>
                            </select>
                        </div>
                    </div>
                    <button type="submit" name="save_db_allergens" class="save-btn">SAVE CHANGES</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<style>
/* MODAL STYLES */
#allergen-trigger-icon { position: fixed; bottom: 30px; right: 30px; background: #ff4d4d; color: white; width: 60px; height: 60px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 24px; cursor: pointer; z-index: 999999; box-shadow: 0 4px 15px rgba(0,0,0,0.3); transition: transform 0.2s; }
#allergen-trigger-icon:hover { transform: scale(1.1); }
#allergen-modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.7); z-index: 9999999; backdrop-filter: blur(4px); }
.allergen-modal-window { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; padding: 30px; border-radius: 12px; width: 700px; max-width: 95%; box-shadow: 0 20px 60px rgba(0,0,0,0.5); font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
#close-allergen-modal { position: absolute; top: 15px; right: 20px; font-size: 28px; cursor: pointer; color: #bbb; line-height: 1; }
#close-allergen-modal:hover { color: #333; }

/* FLOATING TOAST NOTIFICATION */
.allergen-success-msg {
    position: absolute;
    top: 20px;
    left: 50%;
    transform: translateX(-50%);
    background: #28a745;
    color: white;
    padding: 8px 25px;
    border-radius: 50px;
    font-size: 14px;
    font-weight: bold;
    box-shadow: 0 4px 12px rgba(0,0,0,0.2);
    z-index: 100;
    transition: opacity 0.5s ease, top 0.5s ease;
    white-space: nowrap;
    opacity: 0;
}

/* INTERFACE COMPONENTS */
.filter-row { display: flex; gap: 15px; margin-bottom: 20px; background: #f9f9f9; padding: 15px; border-radius: 8px; }
.filter-group { flex: 1; }
.filter-group label { display: block; font-size: 11px; font-weight: bold; text-transform: uppercase; color: #777; margin-bottom: 5px; }
.filter-group input, .filter-group select { width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; }

.transfer-container { display: flex; gap: 20px; align-items: center; }
.transfer-column { flex: 1; }
.transfer-column label { font-size: 13px; font-weight: bold; color: #555; margin-bottom: 10px; display: block; }
.transfer-column select { width: 100%; height: 280px !important; border: 1px solid #ddd; border-radius: 6px; padding: 8px; font-size: 14px; outline: none; }
.transfer-column select:focus { border-color: #ff4d4d; }

.transfer-controls { display: flex; flex-direction: column; gap: 12px; }
.transfer-controls button { padding: 10px 18px; cursor: pointer; background: #fff; border: 1px solid #ccc; border-radius: 6px; font-weight: 600; font-size: 12px; transition: all 0.2s; }
.transfer-controls button:hover { background: #ff4d4d; color: white; border-color: #ff4d4d; }

.save-btn { width: 100%; margin-top: 25px; padding: 16px; background: #007bff; color: white; border: none; border-radius: 8px; font-size: 15px; cursor: pointer; font-weight: bold; letter-spacing: 1px; transition: background 0.2s; }
.save-btn:hover { background: #0056b3; }
</style>

<script>
document.addEventListener("DOMContentLoaded", function() {
    const modal = document.getElementById("allergen-modal-overlay");
    const icon = document.getElementById("allergen-trigger-icon");
    const closeBtn = document.getElementById("close-allergen-modal");
    
    const availList = document.getElementById("available-list");
    const selectedList = document.getElementById("selected-list");
    const groupFilter = document.getElementById("group-filter");
    const searchInput = document.getElementById("allergen-search");
    const form = document.getElementById("allergen-transfer-form");

    // TOAST NOTIFICATION LOGIC
    const successMsg = document.querySelector('.allergen-success-msg');
    if (successMsg) {
        setTimeout(() => {
            successMsg.style.top = '30px';
            successMsg.style.opacity = '1';
        }, 100);

        setTimeout(() => {
            successMsg.style.top = '20px';
            successMsg.style.opacity = '0';
            setTimeout(() => successMsg.remove(), 500);
        }, 3000);
    }

    // MODAL ACTIONS
    const openModal = () => { if(modal) modal.style.display = "block"; };
    const closeModal = () => { if(modal) modal.style.display = "none"; };
    
    if(icon) icon.onclick = openModal;
    if(closeBtn) closeBtn.onclick = closeModal;
    window.onclick = (e) => { if(e.target === modal) closeModal(); };

    // AUTO-OPEN AFTER REDIRECT
    const params = new URLSearchParams(window.location.search);
    if (params.has('allergen_updated')) {
        openModal();
        window.history.replaceState({}, document.title, window.location.pathname);
    }

    // TRANSFER BUTTONS
    document.getElementById("btn-add").onclick = () => {
        Array.from(availList.selectedOptions).forEach(opt => selectedList.appendChild(opt));
    };
    document.getElementById("btn-remove").onclick = () => {
        Array.from(selectedList.selectedOptions).forEach(opt => {
            opt.style.display = 'block'; 
            availList.appendChild(opt);
        });
    };

    // DOUBLE CLICK ACTIONS
    availList.addEventListener('dblclick', () => document.getElementById("btn-add").click());
    selectedList.addEventListener('dblclick', () => document.getElementById("btn-remove").click());

    // SEARCH & FILTER SYNC
    const runFilters = () => {
        const query = searchInput.value.toLowerCase();
        const category = groupFilter.value;
        const options = availList.querySelectorAll('option');

        options.forEach(opt => {
            const name = opt.text.toLowerCase();
            const aliases = opt.getAttribute('data-aliases') ? opt.getAttribute('data-aliases').toLowerCase() : '';
            const groupId = opt.getAttribute('data-group');

            const matchesSearch = name.includes(query) || aliases.includes(query);
            const matchesGroup = (category === 'all' || groupId === category);

            opt.style.display = (matchesSearch && matchesGroup) ? 'block' : 'none';
        });
    };

    if(searchInput) searchInput.oninput = runFilters;
    if(groupFilter) groupFilter.onchange = runFilters;

    // PRE-SUBMIT SELECT ALL
    if(form) {
        form.onsubmit = () => {
            Array.from(selectedList.options).forEach(opt => opt.selected = true);
        };
    }
});
</script>
<?php
}

// --- SAVE DATA TO DATABASE ---
add_action('init', function() {
    if (isset($_POST['save_db_allergens']) && is_user_logged_in()) {
        global $wpdb;
        $user_id = get_current_user_id();
        $table = 'user_allergens_map';

        $wpdb->delete($table, ['user_id' => $user_id]);

        if (!empty($_POST['allergen_ids']) && is_array($_POST['allergen_ids'])) {
            foreach ($_POST['allergen_ids'] as $id) {
                $wpdb->insert($table, [
                    'user_id' => $user_id,
                    'allergen_id' => intval($id)
                ]);
            }
        }
        
        // Refresh with success flag
        wp_redirect(add_query_arg('allergen_updated', '1', $_SERVER['REQUEST_URI']));
        exit;
    }
});