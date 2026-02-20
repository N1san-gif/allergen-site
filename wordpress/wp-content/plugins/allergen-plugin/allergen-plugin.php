<?php
/*
Plugin Name: Allergen Profile Ultimate Structured
Version: 16.0
*/

add_action('wp_footer', 'allergen_profile_ultimate_structured_render');

function allergen_profile_ultimate_structured_render() {
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
                <label>Search:</label>
                <input type="text" id="allergen-search" placeholder="Type to search...">
            </div>
            <div class="filter-group">
                <label>Category:</label>
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
                <label class="col-label">Available Items</label>
                <select id="available-list" size="15" multiple>
                    <?php foreach ($all_allergens as $a) : 
                        $grp = $a->group_name ? $a->group_name : 'Other';
                        $style = in_array($a->allergen_id, $saved_ids) ? 'display:none' : '';
                        ?>
                        <option value="<?php echo $a->allergen_id; ?>" 
                                data-group="<?php echo esc_attr($grp); ?>" 
                                data-name="<?php echo esc_attr($a->allergen_name); ?>"
                                data-aliases="<?php echo esc_attr($a->aliases); ?>"
                                style="<?php echo $style; ?>">
                            <?php echo esc_html($a->allergen_name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
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
/* ПОВЕРНЕННЯ ПОПЕРЕДНЬОГО СТИЛЮ */
#allergen-trigger-icon { position: fixed; bottom: 30px; right: 30px; background: #ff4d4d; color: white; width: 65px; height: 65px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 26px; cursor: pointer; z-index: 999999; box-shadow: 0 5px 20px rgba(0,0,0,0.3); transition: 0.3s; }
#allergen-trigger-icon:hover { transform: scale(1.1); background: #e60000; }

#allergen-modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.75); z-index: 9999999; backdrop-filter: blur(5px); }

.allergen-modal-window { 
    position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); 
    background: white; padding: 40px; border-radius: 15px; width: 950px; max-width: 95%; 
    box-shadow: 0 25px 70px rgba(0,0,0,0.6); font-family: sans-serif; 
}

.modal-title { margin-top:0; border-bottom:2px solid #eee; padding-bottom:15px; font-size: 22px; }

#close-allergen-modal { position: absolute; top: 20px; right: 25px; font-size: 32px; cursor: pointer; color: #ccc; }
#close-allergen-modal:hover { color: #333; }

.filter-row { display: flex; gap: 20px; margin-bottom: 25px; background: #f4f7f6; padding: 20px; border-radius: 10px; }
.filter-group { flex: 1; }
.filter-group label { display: block; font-size: 12px; font-weight: 700; color: #555; text-transform: uppercase; margin-bottom: 8px; }
.filter-group input, .filter-group select { width: 100%; padding: 10px; border: 1px solid #ced4da; border-radius: 6px; }

.transfer-container { display: flex; gap: 25px; align-items: center; }
.transfer-column { flex: 1; }
.col-label { font-size: 14px; font-weight: 700; color: #333; margin-bottom: 12px; display: block; }

#available-list { width: 100%; height: 350px !important; border: 1px solid #ced4da; border-radius: 8px; padding: 10px; font-size: 13px; }

/* ПРАВА КОЛОНКА */
.structured-box { 
    width: 100%; height: 350px; border: 1px solid #ced4da; border-radius: 8px; padding: 15px; 
    background: #fff; overflow-y: auto; line-height: 1.8;
}
.group-row { margin-bottom: 15px; padding-bottom: 5px; border-bottom: 1px solid #f0f0f0; }
.group-title { font-weight: bold; color: #2c3e50; font-size: 13px; }
.tag-item { 
    background: #f8f9fa; border: 1px solid #ddd; padding: 2px 8px; border-radius: 4px; 
    margin: 2px; font-size: 13px; cursor: pointer; display: inline-block;
}
.tag-item:hover { background: #ffeded; color: #ff4d4d; border-color: #ff4d4d; }
.tag-selected { background: #ff4d4d !important; color: white !important; border-color: #ff4d4d !important; }

/* КНОПКИ */
.transfer-controls { display: flex; flex-direction: column; gap: 15px; }
.move-btn { padding: 12px 20px; cursor: pointer; font-weight: bold; border-radius: 8px; border: 1px solid #ddd; background: #f8f9fa; transition: 0.2s; }
.move-btn:hover { background: #ff4d4d; color: white; border-color: #ff4d4d; }

.save-btn { width: 100%; margin-top: 30px; padding: 18px; background: #007bff; color: white; border: none; border-radius: 10px; font-size: 16px; font-weight: bold; cursor: pointer; transition: 0.2s; }
.save-btn:hover { background: #0056b3; transform: translateY(-2px); }
</style>

<script>
document.addEventListener("DOMContentLoaded", function() {
    const modal = document.getElementById("allergen-modal-overlay");
    const icon = document.getElementById("allergen-trigger-icon");
    const closeBtn = document.getElementById("close-allergen-modal");
    const availList = document.getElementById("available-list");
    const displayBox = document.getElementById("structured-selected-display");
    const hiddenContainer = document.getElementById("hidden-inputs-container");
    
    // 1. ЛОГИКА ОТКРЫТИЯ ОКНА ПОСЛЕ ПЕРЕЗАГРУЗКИ
    // Проверяем, есть ли в URL параметр об успешном обновлении
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.has('allergen_updated')) {
        modal.style.display = "block";
    }

    // Также проверяем sessionStorage (на случай обычной перезагрузки пользователем)
    if (sessionStorage.getItem('allergen_modal_open') === 'true') {
        modal.style.display = "block";
    }

    const openM = () => { 
        modal.style.display = "block"; 
        sessionStorage.setItem('allergen_modal_open', 'true'); 
    };
    
    const closeM = () => { 
        modal.style.display = "none"; 
        sessionStorage.removeItem('allergen_modal_open');
        // Опционально: убираем параметр из URL при закрытии, чтобы при след. обнове страницы окно не всплыло само
        const cleanUrl = window.location.protocol + "//" + window.location.host + window.location.pathname;
        window.history.replaceState({}, document.title, cleanUrl);
    };

    if(icon) icon.onclick = openM;
    if(closeBtn) closeBtn.onclick = closeM;

    // --- Остальная логика (selectedData, render, и т.д.) ---

    let selectedData = <?php echo json_encode(array_map('intval', $saved_ids)); ?>.map(id => {
        const opt = availList.querySelector(`option[value="${id}"]`);
        return opt ? { id: id, name: opt.dataset.name, group: opt.dataset.group } : null;
    }).filter(x => x);

    let itemsToRemove = new Set();

    const renderStructured = () => {
        displayBox.innerHTML = "";
        hiddenContainer.innerHTML = "";
        
        if (selectedData.length === 0) {
            displayBox.innerHTML = '<span style="color:#999">No restrictions selected.</span>';
            return;
        }

        const grouped = selectedData.reduce((acc, item) => {
            if (!acc[item.group]) acc[item.group] = [];
            acc[item.group].push(item);
            return acc;
        }, {});

        Object.keys(grouped).sort().forEach(groupName => {
            const row = document.createElement("div");
            row.className = "group-row";
            const title = document.createElement("span");
            title.className = "group-title";
            title.innerText = groupName + ": ";
            row.appendChild(title);

            grouped[groupName].sort((a,b) => a.name.localeCompare(b.name)).forEach((item, idx) => {
                const tag = document.createElement("span");
                tag.className = "tag-item" + (itemsToRemove.has(item.id) ? " tag-selected" : "");
                tag.innerText = item.name;
                tag.onclick = () => {
                    if(itemsToRemove.has(item.id)) itemsToRemove.delete(item.id);
                    else itemsToRemove.add(item.id);
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

    const addItem = () => {
        Array.from(availList.selectedOptions).forEach(opt => {
            const id = parseInt(opt.value);
            if (!selectedData.find(x => x.id === id)) {
                selectedData.push({ id: id, name: opt.dataset.name, group: opt.dataset.group });
                opt.style.display = "none";
            }
        });
        renderStructured();
    };

    const removeItem = (id) => {
        selectedData = selectedData.filter(x => x.id !== id);
        itemsToRemove.delete(id);
        const opt = availList.querySelector(`option[value="${id}"]`);
        if (opt) opt.style.display = "block";
        renderStructured();
    };

    document.getElementById("btn-remove-allg").onclick = () => {
        itemsToRemove.forEach(id => removeItem(id));
    };

    document.getElementById("btn-add-allg").onclick = addItem;
    availList.ondblclick = addItem;

    // Фільтри
    const searchInput = document.getElementById("allergen-search");
    const groupFilter = document.getElementById("group-filter");
    const filterFn = () => {
        const q = searchInput.value.toLowerCase(), cat = groupFilter.value;
        Array.from(availList.options).forEach(opt => {
            if (selectedData.find(x => x.id === parseInt(opt.value))) return;
            const mS = opt.text.toLowerCase().includes(q) || (opt.dataset.aliases || "").toLowerCase().includes(q);
            const mC = (cat === 'all' || opt.dataset.group === cat);
            opt.style.display = (mS && mC) ? "block" : "none";
        });
    };
    searchInput.oninput = filterFn; 
    groupFilter.onchange = filterFn;

    // Сообщение об успехе
    const toast = document.querySelector('.allergen-success-msg');
    if(toast) {
        setTimeout(() => { toast.style.opacity = '1'; toast.style.top = '30px'; }, 100);
        setTimeout(() => { toast.style.opacity = '0'; }, 3000);
    }

    renderStructured();
});
</script>
<?php
}

// SAVE ACTION
add_action('init', function() {
    if (isset($_POST['save_db_allergens']) && is_user_logged_in()) {
        global $wpdb;
        $user_id = get_current_user_id();
        $wpdb->delete('user_allergens_map', ['user_id' => $user_id]);
        if (!empty($_POST['allergen_ids'])) {
            foreach ($_POST['allergen_ids'] as $id) {
                $wpdb->insert('user_allergens_map', ['user_id' => $user_id, 'allergen_id' => intval($id)]);
            }
        }
        wp_redirect(add_query_arg('allergen_updated', '1', $_SERVER['REQUEST_URI']));
        exit;
    }
});