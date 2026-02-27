<?php
/*
Plugin Name: Allergen Profile Ultimate (Full Integration)
Version: 22.0
Description: Готова версія: індивідуальні профілі, два незалежних перемикачі, фільтрація ВСЕРЕДИНІ карток (шорткод) та залізобетонний фікс кнопки "Назад".
*/

// =========================================================================
// ХЕЛПЕРИ ДЛЯ ПОШУКУ АЛЕРГЕНІВ
// =========================================================================

// Отримуємо список заборонених слів (алергени + аліаси) для користувача
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
    foreach ($allergen_data as $row) {
        $forbidden_words[] = mb_strtolower($row->allergen_name);
        if ($row->aliases) {
            $aliases = explode(',', $row->aliases);
            foreach ($aliases as $alias) {
                $forbidden_words[] = mb_strtolower(trim($alias));
            }
        }
    }
    return $forbidden_words;
}

// Шукаємо алерген у тексті
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

// =========================================================================
// 1. ПЕРЕДАЧА ДАНИХ КОРИСТУВАЧА В JS
// =========================================================================
add_action('wp_head', function() {
    if (is_user_logged_in()) {
        global $wpdb;
        $user_id = get_current_user_id();
        $saved_ids = $wpdb->get_col($wpdb->prepare("SELECT allergen_id FROM user_allergens_map WHERE user_id = %d", $user_id));
        
        if (!empty($saved_ids)) {
            $placeholders = implode(',', array_fill(0, count($saved_ids), '%d'));
            $allergen_data = $wpdb->get_results($wpdb->prepare("
                SELECT a.allergen_name, GROUP_CONCAT(al.alias_name) as aliases
                FROM allergens a
                LEFT JOIN allergen_aliases al ON a.allergen_id = al.allergen_id
                WHERE a.allergen_id IN ($placeholders)
                GROUP BY a.allergen_id
            ", $saved_ids));

            echo "<script>window.userRestrictions = " . json_encode($allergen_data) . ";</script>";
        }
    }
});

// =========================================================================
// 2. РЕНДЕР МОДАЛЬНОГО ВІКНА ТА ЛОГІКИ ІНТЕРФЕЙСУ
// =========================================================================
add_action('wp_footer', 'allergen_profile_final_integrated_render');

function allergen_profile_final_integrated_render() {
    echo '<div id="allergen-trigger-icon">⚠️</div>';
    echo '<div id="allergen-modal-overlay">';
    echo '<div class="allergen-modal-window">';
    echo '<span id="close-allergen-modal">&times;</span>';

    if (!is_user_logged_in()) {
        ?>
        <div style="text-align: center; padding: 40px 20px;">
            <h3 style="font-size: 24px; color: #d9534f; margin-bottom: 15px;">🔒 Authentication Required</h3>
            <p style="font-size: 16px; color: #555; margin-bottom: 30px;">
                Every user has their own individual allergen profile. Please log in or register to create and manage your personal restrictions.
            </p>
            <a href="<?php echo esc_url(wp_login_url(get_permalink())); ?>" class="save-btn" style="display: inline-block; width: auto; padding: 12px 30px; text-decoration: none; margin-right: 15px;">Log In</a>
            <a href="<?php echo esc_url(wp_registration_url()); ?>" class="move-btn" style="display: inline-block; width: auto; padding: 12px 30px; text-decoration: none;">Register</a>
        </div>
        <?php
    } else {
        global $wpdb;
        $user_id = get_current_user_id();
        $saved_ids = $wpdb->get_col($wpdb->prepare("SELECT allergen_id FROM user_allergens_map WHERE user_id = %d", $user_id));

        $all_groups = $wpdb->get_results("SELECT group_id, group_name FROM allergen_groups ORDER BY group_name ASC");
        $all_allergens = $wpdb->get_results("
            SELECT a.allergen_id, a.allergen_name, a.group_id, g.group_name, GROUP_CONCAT(al.alias_name) as aliases
            FROM allergens a
            LEFT JOIN allergen_groups g ON a.group_id = g.group_id
            LEFT JOIN allergen_aliases al ON a.allergen_id = al.allergen_id
            GROUP BY a.allergen_id
            ORDER BY a.allergen_name ASC
        ");
        ?>
        <h3 class="modal-title">My Individual Allergen Profile</h3>

        <div class="filter-row">
            <div class="filter-group">
                <label>SEARCH:</label>
                <input type="text" id="allergen-search" placeholder="Type to search allergens...">
            </div>
            <div class="filter-group">
                <label>CATEGORY:</label>
                <select id="group-filter">
                    <option value="all">All Groups</option>
                    <?php foreach ($all_groups as $g) : ?>
                        <option value="<?php echo esc_attr($g->group_name); ?>"><?php echo esc_html($g->group_name); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="transfer-container">
            <div class="transfer-column">
                <label class="col-label">Available Items (Double-click to add)</label>
                <div id="available-items-box" class="structured-box">
                    <?php foreach ($all_allergens as $a) : 
                        $grp = $a->group_name ? $a->group_name : 'Other';
                        $isHidden = in_array($a->allergen_id, $saved_ids) ? 'style="display:none"' : '';
                        ?>
                        <div class="list-item-full" 
                             data-id="<?php echo $a->allergen_id; ?>"
                             data-group="<?php echo esc_attr($grp); ?>" 
                             data-name="<?php echo esc_attr($a->allergen_name); ?>"
                             data-aliases="<?php echo esc_attr($a->aliases); ?>"
                             <?php echo $isHidden; ?>>
                            <?php echo esc_html($a->allergen_name); ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="transfer-controls">
                <button type="button" id="btn-add-allg" class="move-btn">Add &raquo;</button>
                <button type="button" id="btn-remove-allg" class="move-btn">&laquo; Remove</button>
            </div>

            <div class="transfer-column">
                <label class="col-label">My Restrictions (Grouped)</label>
                <div id="structured-selected-display" class="structured-box">
                </div>
            </div>
        </div>
        
        <form method="post" id="allergen-hidden-form">
            <div class="protection-settings" style="margin-top: 20px; padding: 20px; background: #fdf2f2; border-radius: 8px; border: 1px solid #ffcccc;">
                <label style="font-weight: bold; color: #333; margin-bottom: 15px; display: block; border-bottom: 1px solid #f5dcdc; padding-bottom: 10px;">🛡️ Protection Settings (Independent):</label>
                
                <div class="toggle-row">
                    <span class="toggle-label-text">1. Highlight warnings inside recipe text & cards</span>
                    <label class="switch">
                        <input type="checkbox" name="prot_highlight" value="1" <?php checked(get_user_meta($user_id, 'allergen_setting_highlight', true), '1'); ?>>
                        <span class="slider round"></span>
                    </label>
                </div>

                <div class="toggle-row">
                    <span class="toggle-label-text">2. Strict Mode: Hide dangerous recipes entirely</span>
                    <label class="switch">
                        <input type="checkbox" name="prot_strict" value="1" <?php checked(get_user_meta($user_id, 'allergen_setting_strict', true), '1'); ?>>
                        <span class="slider round"></span>
                    </label>
                </div>
            </div>

            <div id="hidden-inputs-container"></div>
            <button type="submit" name="save_db_allergens" class="save-btn">SAVE CHANGES</button>
        </form>
        <?php
    }
    echo '</div></div>';
    ?>

<style>
/* ОСНОВНІ СТИЛІ */
* { box-sizing: border-box; } 

#allergen-trigger-icon { position: fixed; bottom: 30px; right: 30px; background: #ff4d4d; color: white; width: 65px; height: 65px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 26px; cursor: pointer; z-index: 999999; box-shadow: 0 5px 20px rgba(0,0,0,0.3); transition: 0.3s; }
#allergen-trigger-icon:hover { transform: scale(1.1); }

#allergen-modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.75); z-index: 9999999; backdrop-filter: blur(5px); }
.allergen-modal-window { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; padding: 40px; border-radius: 15px; width: 1100px; max-width: 95vw; max-height: 90vh; overflow-y: auto; box-shadow: 0 25px 70px rgba(0,0,0,0.6); font-family: sans-serif; }
.modal-title { margin-top:0; border-bottom:2px solid #eee; padding-bottom:15px; font-size: 22px; }
#close-allergen-modal { position: absolute; top: 20px; right: 25px; font-size: 32px; cursor: pointer; color: #ccc; transition: 0.2s; }
#close-allergen-modal:hover { color: #333; }

/* ФІЛЬТРИ ТА СПИСКИ */
.filter-row { display: flex; gap: 30px; margin-bottom: 25px; background: #f4f7f6; padding: 20px; border-radius: 10px; }
.filter-group { flex: 1; }
.filter-group label { display: block; font-size: 12px; font-weight: 700; color: #555; margin-bottom: 8px; text-transform: uppercase; }
.filter-group input, .filter-group select { width: 100%; padding: 12px; border: 1px solid #ced4da; border-radius: 6px; }

.transfer-container { display: flex; gap: 20px; align-items: stretch; }
.transfer-column { flex: 1; min-width: 0; display: flex; flex-direction: column; }
.col-label { font-size: 14px; font-weight: 700; margin-bottom: 12px; display: block; color: #333; }

.structured-box { width: 100%; height: 350px; border: 1px solid #ced4da; border-radius: 8px; padding: 15px; background: #fff; overflow-y: auto; line-height: 1.8; }
.list-item-full { display: block; width: 100%; padding: 8px 12px; background: #f8f9fa; border: 1px solid #eee; margin-bottom: 4px; border-radius: 4px; cursor: pointer; font-size: 13px; transition: 0.2s; }
.list-item-full:hover { background: #e9ecef; }
.list-item-full.selected-for-add { background: #007bff !important; color: white; border-color: #007bff; }

.group-row { margin-bottom: 15px; padding-bottom: 5px; border-bottom: 1px solid #f0f0f0; }
.group-title { font-weight: bold; color: #2c3e50; font-size: 13px; }
.tag-item { background: #f1f3f5; border: 1px solid #ddd; padding: 3px 8px; border-radius: 4px; margin: 2px; font-size: 13px; cursor: pointer; display: inline-block; transition: 0.2s; }
.tag-item:hover { background: #ffeded; border-color: #ff4d4d; color: #ff4d4d; }
.tag-item.tag-selected { background: #ff4d4d; color: white; border-color: #ff4d4d; }

.transfer-controls { display: flex; flex-direction: column; justify-content: center; gap: 15px; padding-top: 30px; flex-shrink: 0; }
.move-btn { width: 120px; padding: 12px 15px; cursor: pointer; font-weight: bold; border-radius: 8px; border: 1px solid #ddd; background: #f8f9fa; transition: 0.2s; text-align: center; }
.move-btn:hover { background: #007bff; color: white; border-color: #007bff; }

.save-btn { width: 100%; margin-top: 25px; padding: 18px; background: #007bff; color: white; border: none; border-radius: 10px; font-size: 16px; font-weight: bold; cursor: pointer; transition: 0.2s; }
.save-btn:hover { background: #0056b3; transform: translateY(-2px); }

/* TOGGLE SWITCH СТИЛІ */
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
document.addEventListener("DOMContentLoaded", function() {
    const modal = document.getElementById("allergen-modal-overlay");
    const closeM = () => { modal.style.display = "none"; };
    
    document.getElementById("allergen-trigger-icon").onclick = () => { modal.style.display = "block"; };
    document.getElementById("close-allergen-modal").onclick = closeM;

    // ЖОРСТКИЙ ФІКС КНОПКИ "НАЗАД" (BFCache)
    window.addEventListener("pageshow", function(event) {
        if (event.persisted) {
            modal.style.display = "none";
        }
    });

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
                    
                    tag.onclick = () => { 
                        itemsToRemove.has(item.id) ? itemsToRemove.delete(item.id) : itemsToRemove.add(item.id); 
                        renderStructured(); 
                    };
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

        availBox.onclick = (e) => { 
            if(e.target.classList.contains('list-item-full')) e.target.classList.toggle('selected-for-add'); 
        };
        
        availBox.ondblclick = (e) => {
            if(e.target.classList.contains('list-item-full')) {
                const id = parseInt(e.target.dataset.id);
                if(!selectedData.find(x => x.id === id)) {
                    selectedData.push({id, name: e.target.dataset.name, group: e.target.dataset.group});
                    e.target.style.display = "none"; e.target.classList.remove('selected-for-add');
                    renderStructured();
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

        const removeItem = (id) => {
            selectedData = selectedData.filter(x => x.id !== id); 
            itemsToRemove.delete(id);
            const el = availBox.querySelector(`.list-item-full[data-id="${id}"]`);
            if(el) { el.style.display = "block"; filterFn(); }
            renderStructured();
        };
        document.getElementById("btn-remove-allg").onclick = () => { itemsToRemove.forEach(id => removeItem(id)); };

        const filterFn = () => {
            const q = document.getElementById("allergen-search").value.toLowerCase();
            const cat = document.getElementById("group-filter").value;
            availBox.querySelectorAll('.list-item-full').forEach(el => {
                if (selectedData.find(x => x.id === parseInt(el.dataset.id))) { el.style.display = "none"; return; }
                const mS = el.innerText.toLowerCase().includes(q) || (el.dataset.aliases || "").toLowerCase().includes(q);
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

// =========================================================================
// 3. ЗБЕРЕЖЕННЯ ДАНИХ У БАЗУ
// =========================================================================
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
        
        // Перенаправляємо просто на поточну сторінку БЕЗ параметрів. Окно буде закрите.
        wp_safe_redirect(wp_get_referer() ? wp_get_referer() : home_url());
        exit;
    }
});

// =========================================================================
// 4. CREATE "RECIPES" POST TYPE & ВНУТРІШНІЙ ФІЛЬТР РЕЦЕПТІВ
// =========================================================================
add_action('init', 'allergen_register_recipe_post_type');

function allergen_register_recipe_post_type() {
    register_post_type('recipe', array(
        'labels'      => array('name' => 'Recipes', 'singular_name' => 'Recipe'),
        'public'      => true,
        'has_archive' => true,
        'menu_icon'   => 'dashicons-carrot',
        'supports'    => array('title', 'editor', 'thumbnail', 'excerpt'),
        'show_in_rest'=> true,
    ));
}

add_filter('the_content', 'allergen_engine_filter_recipes');

function allergen_engine_filter_recipes($content) {
    if (!is_singular('recipe') || !is_user_logged_in()) return $content;

    $user_id = get_current_user_id();
    $forbidden_words = allergen_get_user_forbidden_words($user_id);
    $found_allergen = allergen_find_in_text($content, $forbidden_words);

    if ($found_allergen) {
        $is_strict = get_user_meta($user_id, 'allergen_setting_strict', true) === '1';
        $is_highlight = get_user_meta($user_id, 'allergen_setting_highlight', true) === '1';

        if ($is_strict) {
            return '
            <div class="allergen-server-block" style="border: 2px solid #ff4d4d; border-radius: 10px; padding: 30px; text-align: center; background: #fff0f0; margin: 20px 0;">
                <h3 style="color: #d9534f; margin-top: 0;">⚠️ Warning! Recipe Blocked</h3>
                <p>This dish contains: <strong style="text-transform: uppercase;">' . esc_html($found_allergen) . '</strong></p>
                <button onclick="document.getElementById(\'hidden-recipe-content\').style.display=\'block\'; this.style.display=\'none\';" style="background: #d9534f; color: white; border: none; padding: 10px 20px; border-radius: 5px; cursor: pointer; margin-top: 10px;">Show anyway</button>
            </div>
            <div id="hidden-recipe-content" style="display: none;">' . $content . '</div>';
        } elseif ($is_highlight) {
            return '
            <div style="border: 4px solid #ff4d4d; padding: 25px 20px 20px; position: relative; background: #fffafa; border-radius: 8px; margin-top: 25px;">
                <div style="background: #ff4d4d; color: white; padding: 5px 15px; font-weight: bold; position: absolute; top: -15px; left: 20px; border-radius: 5px; font-size: 14px;">
                    ⚠️ DANGEROUS: CONTAINS ' . strtoupper(esc_html($found_allergen)) . '
                </div>
                <div>' . $content . '</div>
            </div>';
        }
    }
    return $content;
}

// =========================================================================
// 5. RECIPE GRID SHORTCODE (ВІЗУАЛЬНІ КАРТКИ З ФІЛЬТРАЦІЄЮ)
// =========================================================================
add_shortcode('recipe_grid', 'allergen_recipe_grid_shortcode');

function allergen_recipe_grid_shortcode() {
    $user_id = is_user_logged_in() ? get_current_user_id() : 0;
    $forbidden_words = $user_id ? allergen_get_user_forbidden_words($user_id) : [];
    $is_strict = $user_id ? get_user_meta($user_id, 'allergen_setting_strict', true) === '1' : false;
    $is_highlight = $user_id ? get_user_meta($user_id, 'allergen_setting_highlight', true) === '1' : false;

    $query = new WP_Query(array('post_type' => 'recipe', 'posts_per_page' => 12, 'orderby' => 'date', 'order' => 'DESC'));
    $output = '<div class="recipe-grid-container">';

    if ($query->have_posts()) {
        while ($query->have_posts()) {
            $query->the_post();
            global $post;
            
            // Перевіряємо заголовок та контент картки на наявність алергену
            $text_to_check = get_the_title() . ' ' . $post->post_content;
            $found_allergen = allergen_find_in_text($text_to_check, $forbidden_words);

            // ЯКЩО ВКЛЮЧЕНО STRICT МІД - ВЗАГАЛІ ПРОПУСКАЄМО КАРТКУ (не малюємо її)
            if ($found_allergen && $is_strict) {
                continue; 
            }

            $img = get_the_post_thumbnail_url(get_the_ID(), 'medium');
            if (!$img) $img = 'https://via.placeholder.com/300x200?text=No+Image';

            $card_class = "recipe-card-block";
            $badge_html = "";

            // ЯКЩО ВКЛЮЧЕНО HIGHLIGHT - ДОДАЄМО ЧЕРВОНУ РАМКУ ТА ПЛАШКУ
            if ($found_allergen && $is_highlight) {
                $card_class .= " recipe-card-danger";
                $badge_html = '<div class="allergen-card-badge">⚠️ CONTAINS ' . strtoupper(esc_html($found_allergen)) . '</div>';
            }

            $output .= '
            <div class="' . $card_class . '">
                ' . $badge_html . '
                <div class="recipe-card-image" style="background-image: url(' . esc_url($img) . ');"></div>
                <div class="recipe-card-content">
                    <h4>' . get_the_title() . '</h4>
                    <p>' . wp_trim_words(get_the_excerpt(), 10) . '</p>
                    <a href="' . get_permalink() . '" class="recipe-card-btn">View Recipe</a>
                </div>
            </div>';
        }
        wp_reset_postdata();
    } else {
        $output .= '<p>No recipes found.</p>';
    }

    $output .= '</div>';

    // Додав стилі для небезпечних карток (recipe-card-danger та allergen-card-badge)
    $output .= '
    <style>
    .recipe-grid-container { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 25px; padding: 20px 0; }
    .recipe-card-block { background: white; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.1); transition: transform 0.3s ease; border: 1px solid #eee; position: relative; }
    .recipe-card-block:hover { transform: translateY(-5px); }
    
    /* СТИЛІ ДЛЯ НЕБЕЗПЕЧНИХ КАРТОК (HIGHLIGHT) */
    .recipe-card-danger { border: 3px solid #ff4d4d; }
    .allergen-card-badge { position: absolute; top: 10px; left: 10px; background: #ff4d4d; color: white; padding: 5px 10px; font-size: 12px; font-weight: bold; border-radius: 6px; z-index: 2; box-shadow: 0 2px 5px rgba(0,0,0,0.2); }

    .recipe-card-image { height: 180px; background-size: cover; background-position: center; }
    .recipe-card-content { padding: 15px; }
    .recipe-card-content h4 { margin: 0 0 10px 0; font-size: 18px; color: #333; }
    .recipe-card-content p { font-size: 14px; color: #666; margin-bottom: 15px; }
    .recipe-card-btn { display: inline-block; padding: 8px 15px; background: #ff4d4d; color: white !important; text-decoration: none !important; border-radius: 5px; font-weight: bold; font-size: 14px; }
    .recipe-card-btn:hover { background: #d94343; }
    </style>';

    return $output;
}
// =========================================================================
// ОЧИЩЕННЯ БАЗИ ДАНИХ ПРИ ВИДАЛЕННІ КОРИСТУВАЧА
// =========================================================================
add_action('delete_user', 'allergen_profile_cleanup_on_user_delete');

function allergen_profile_cleanup_on_user_delete($user_id) {
    global $wpdb;
    // Видаляємо всі записи алергенів для цього user_id
    $wpdb->delete('user_allergens_map', array('user_id' => $user_id), array('%d'));
}