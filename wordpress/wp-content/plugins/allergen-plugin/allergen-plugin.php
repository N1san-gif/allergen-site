<?php
/*
Plugin Name: Allergen Profile Ultimate (Full Integration)
Version: 19.0
Description: Готова версія: широке вікно, групування тегами, автоперевірка рецептів та виправлений дизайн колонок.
*/

// =========================================================================
// 1. ПЕРЕДАЧА ДАНИХ КОРИСТУВАЧА В JS (ДЛЯ АВТОПЕРЕВІРКИ РЕЦЕПТІВ)
// =========================================================================
add_action('wp_head', function() {
    if (is_user_logged_in()) {
        global $wpdb;
        $user_id = get_current_user_id();
        $saved_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT allergen_id FROM user_allergens_map WHERE user_id = %d", 
            $user_id
        ));
        
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
    global $wpdb;
    
    $saved_ids = []; 
    if (is_user_logged_in()) {
        $user_id = get_current_user_id();
        $saved_ids = $wpdb->get_col($wpdb->prepare("SELECT allergen_id FROM user_allergens_map WHERE user_id = %d", $user_id));
    }

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

<div id="allergen-trigger-icon">⚠️</div>

<div id="allergen-modal-overlay">
    <div class="allergen-modal-window">
        <span id="close-allergen-modal">&times;</span>
        <h3 class="modal-title">My Allergen Profile</h3>

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
            <div id="hidden-inputs-container"></div>
            <button type="submit" name="save_db_allergens" class="save-btn">SAVE CHANGES</button>
        </form>
    </div>
</div>

<style>
/* ОСНОВНІ СТИЛІ */
* { box-sizing: border-box; } /* Гарантує, що рамки не ламають ширину */

#allergen-trigger-icon { position: fixed; bottom: 30px; right: 30px; background: #ff4d4d; color: white; width: 65px; height: 65px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 26px; cursor: pointer; z-index: 999999; box-shadow: 0 5px 20px rgba(0,0,0,0.3); transition: 0.3s; }
#allergen-trigger-icon:hover { transform: scale(1.1); }

#allergen-modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.75); z-index: 9999999; backdrop-filter: blur(5px); }
.allergen-modal-window { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; padding: 40px; border-radius: 15px; width: 1100px; max-width: 95vw; max-height: 90vh; overflow-y: auto; box-shadow: 0 25px 70px rgba(0,0,0,0.6); font-family: sans-serif; }
.modal-title { margin-top:0; border-bottom:2px solid #eee; padding-bottom:15px; font-size: 22px; }
#close-allergen-modal { position: absolute; top: 20px; right: 25px; font-size: 32px; cursor: pointer; color: #ccc; transition: 0.2s; }
#close-allergen-modal:hover { color: #333; }

/* ФІЛЬТРИ */
.filter-row { display: flex; gap: 30px; margin-bottom: 25px; background: #f4f7f6; padding: 20px; border-radius: 10px; }
.filter-group { flex: 1; }
.filter-group label { display: block; font-size: 12px; font-weight: 700; color: #555; margin-bottom: 8px; text-transform: uppercase; }
.filter-group input, .filter-group select { width: 100%; padding: 12px; border: 1px solid #ced4da; border-radius: 6px; }

/* КОЛОНКИ (ВИПРАВЛЕНИЙ FLEXBOX) */
.transfer-container { display: flex; gap: 20px; align-items: stretch; }
.transfer-column { flex: 1; min-width: 0; display: flex; flex-direction: column; }
.col-label { font-size: 14px; font-weight: 700; margin-bottom: 12px; display: block; color: #333; }

/* СПИСКИ */
.structured-box { width: 100%; height: 400px; border: 1px solid #ced4da; border-radius: 8px; padding: 15px; background: #fff; overflow-y: auto; line-height: 1.8; }
.list-item-full { display: block; width: 100%; padding: 8px 12px; background: #f8f9fa; border: 1px solid #eee; margin-bottom: 4px; border-radius: 4px; cursor: pointer; font-size: 13px; transition: 0.2s; }
.list-item-full:hover { background: #e9ecef; }
.list-item-full.selected-for-add { background: #007bff !important; color: white; border-color: #007bff; }

/* ТЕГИ У ПРАВІЙ КОЛОНЦІ */
.group-row { margin-bottom: 15px; padding-bottom: 5px; border-bottom: 1px solid #f0f0f0; }
.group-title { font-weight: bold; color: #2c3e50; font-size: 13px; }
.tag-item { background: #f1f3f5; border: 1px solid #ddd; padding: 3px 8px; border-radius: 4px; margin: 2px; font-size: 13px; cursor: pointer; display: inline-block; transition: 0.2s; }
.tag-item:hover { background: #ffeded; border-color: #ff4d4d; color: #ff4d4d; }
.tag-item.tag-selected { background: #ff4d4d; color: white; border-color: #ff4d4d; }

/* КНОПКИ КЕРУВАННЯ ПО ЦЕНТРУ */
.transfer-controls { display: flex; flex-direction: column; justify-content: center; gap: 15px; padding-top: 30px; flex-shrink: 0; }
.move-btn { width: 120px; padding: 12px 15px; cursor: pointer; font-weight: bold; border-radius: 8px; border: 1px solid #ddd; background: #f8f9fa; transition: 0.2s; text-align: center; }
.move-btn:hover { background: #007bff; color: white; border-color: #007bff; }

.save-btn { width: 100%; margin-top: 25px; padding: 18px; background: #007bff; color: white; border: none; border-radius: 10px; font-size: 16px; font-weight: bold; cursor: pointer; transition: 0.2s; }
.save-btn:hover { background: #0056b3; transform: translateY(-2px); }

/* ДИЗАЙН ПОПЕРЕДЖЕННЯ В РЕЦЕПТІ */
.allergen-warning-overlay { position: absolute; top: 0; left: 0; right: 0; bottom: 0; background: rgba(255, 255, 255, 0.95); display: flex; align-items: center; justify-content: center; text-align: center; z-index: 10; border-radius: inherit; padding: 20px; border: 2px solid #ff4d4d; }
.warning-content p { color: #d9534f; font-weight: bold; margin-bottom: 10px; font-size: 16px; }
.warning-content button { background: #5bc0de; color: white; border: none; padding: 8px 15px; border-radius: 4px; cursor: pointer; font-weight: bold; }
.warning-content button:hover { background: #31b0d5; }
</style>

<script>
document.addEventListener("DOMContentLoaded", function() {
    // ---- 1. ЛОГІКА ВІКНА ТА СТАНУ ----
    const modal = document.getElementById("allergen-modal-overlay");
    const availBox = document.getElementById("available-items-box");
    const displayBox = document.getElementById("structured-selected-display");
    const hiddenContainer = document.getElementById("hidden-inputs-container");

    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.has('allergen_updated') || sessionStorage.getItem('allgOpen') === 'true') {
        modal.style.display = "block";
    }

    const closeM = () => { 
        modal.style.display = "none"; 
        sessionStorage.removeItem('allgOpen');
        const cleanUrl = window.location.protocol + "//" + window.location.host + window.location.pathname;
        window.history.replaceState({}, document.title, cleanUrl);
    };

    document.getElementById("allergen-trigger-icon").onclick = () => { modal.style.display = "block"; sessionStorage.setItem('allgOpen', 'true'); };
    document.getElementById("close-allergen-modal").onclick = closeM;

    // ---- 2. РЕНДЕР ПРАВОЇ КОЛОНКИ (ТЕГИ) ----
    let selectedData = <?php echo json_encode(array_map('intval', $saved_ids)); ?>.map(id => {
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
                
                // Клік: виділити для видалення. Подвійний клік: видалити одразу.
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

    // ---- 3. ДОДАВАННЯ ТА ВИДАЛЕННЯ АЛЕРГЕНІВ ----
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
        if(el) {
            el.style.display = "block"; // Повертаємо у лівий список
            // Оновлюємо фільтри, щоб прихований елемент не з'явився, якщо він не підходить під поточний пошук
            filterFn(); 
        }
        renderStructured();
    };
    document.getElementById("btn-remove-allg").onclick = () => { itemsToRemove.forEach(id => removeItem(id)); };

    // ---- 4. ФІЛЬТРИ ТА ПОШУК ----
    const filterFn = () => {
        const q = document.getElementById("allergen-search").value.toLowerCase();
        const cat = document.getElementById("group-filter").value;
        availBox.querySelectorAll('.list-item-full').forEach(el => {
            // Якщо елемент вже обрано, він має бути прихований
            if (selectedData.find(x => x.id === parseInt(el.dataset.id))) {
                el.style.display = "none";
                return;
            }
            const mS = el.innerText.toLowerCase().includes(q) || (el.dataset.aliases || "").toLowerCase().includes(q);
            const mC = (cat === 'all' || el.dataset.group === cat);
            el.style.display = (mS && mC) ? "block" : "none";
        });
    };
    document.getElementById("allergen-search").oninput = filterFn;
    document.getElementById("group-filter").onchange = filterFn;

    renderStructured();
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
        
        // Видаляємо старі записи
        $wpdb->delete('user_allergens_map', ['user_id' => $user_id]);
        
        // Додаємо нові
        if (!empty($_POST['allergen_ids'])) {
            foreach ($_POST['allergen_ids'] as $id) {
                $wpdb->insert('user_allergens_map', ['user_id' => $user_id, 'allergen_id' => intval($id)]);
            }
        }
        
        // Перенаправляємо назад із параметром успішного збереження
        wp_redirect(add_query_arg('allergen_updated', '1', $_SERVER['REQUEST_URI']));
        exit;
    }
});
// =========================================================================
// 4. CREATE "RECIPES" POST TYPE & BACKEND FILTER ENGINE
// =========================================================================

// 4.1. Register Custom Post Type "Recipe" (will appear in admin menu)
add_action('init', 'allergen_register_recipe_post_type');

function allergen_register_recipe_post_type() {
    $labels = array(
        'name'               => 'Recipes',
        'singular_name'      => 'Recipe',
        'menu_name'          => 'Recipes',
        'add_new'            => 'Add Recipe',
        'add_new_item'       => 'Add New Recipe',
        'edit_item'          => 'Edit Recipe',
        'all_items'          => 'All Recipes',
    );

    $args = array(
        'labels'             => $labels,
        'public'             => true,
        'has_archive'        => true,
        'menu_icon'          => 'dashicons-carrot', // Carrot icon in admin sidebar
        'supports'           => array('title', 'editor', 'thumbnail', 'excerpt'),
        'show_in_rest'       => true, // Support for Gutenberg block editor
    );

    register_post_type('recipe', $args);
}

// 4.2. Server-side recipe checking and blocking
add_filter('the_content', 'allergen_engine_filter_recipes');

function allergen_engine_filter_recipes($content) {
    // Run ONLY on our "Recipe" pages and only for logged-in users
    if (!is_singular('recipe') || !is_user_logged_in()) {
        return $content;
    }

    global $wpdb;
    $user_id = get_current_user_id();

    // Get user's allergen IDs
    $saved_ids = $wpdb->get_col($wpdb->prepare(
        "SELECT allergen_id FROM user_allergens_map WHERE user_id = %d", 
        $user_id
    ));

    if (empty($saved_ids)) {
        return $content; // No allergies — show the recipe
    }}
    // =========================================================================
// 5. RECIPE GRID SHORTCODE (VISUAL BLOCKS)
// =========================================================================
add_shortcode('recipe_grid', 'allergen_recipe_grid_shortcode');

function allergen_recipe_grid_shortcode() {
    $args = array(
        'post_type'      => 'recipe',
        'posts_per_page' => 6,
        'orderby'        => 'date',
        'order'          => 'DESC'
    );

    $query = new WP_Query($args);
    $output = '<div class="recipe-grid-container">';

    if ($query->have_posts()) {
        while ($query->have_posts()) {
            $query->the_post();
            $img = get_the_post_thumbnail_url(get_the_ID(), 'medium');
            if (!$img) $img = 'https://via.placeholder.com/300x200?text=No+Image';

            $output .= '
            <div class="recipe-card-block">
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
        $output .= '<p>No recipes found. Add some in the admin panel!</p>';
    }

    $output .= '</div>';

    // Додаємо CSS стилі для сітки
    $output .= '
    <style>
    .recipe-grid-container {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
        gap: 25px;
        padding: 20px 0;
    }
    .recipe-card-block {
        background: white;
        border-radius: 12px;
        overflow: hidden;
        box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        transition: transform 0.3s ease;
        border: 1px solid #eee;
    }
    .recipe-card-block:hover {
        transform: translateY(-5px);
    }
    .recipe-card-image {
        height: 180px;
        background-size: cover;
        background-position: center;
    }
    .recipe-card-content {
        padding: 15px;
    }
    .recipe-card-content h4 {
        margin: 0 0 10px 0;
        font-size: 18px;
        color: #333;
    }
    .recipe-card-content p {
        font-size: 14px;
        color: #666;
        margin-bottom: 15px;
    }
    .recipe-card-btn {
        display: inline-block;
        padding: 8px 15px;
        background: #ff4d4d;
        color: white !important;
        text-decoration: none !important;
        border-radius: 5px;
        font-weight: bold;
        font-size: 14px;
    }
    .recipe-card-btn:hover {
        background: #d94343;
    }
    </style>';

    return $output;
}