add_shortcode('admin_post_grid', 'render_admin_post_grid_ui');

function render_admin_post_grid_ui() {
    // 1. 取得「未分類」的分類 ID 作為預設值
    $uncat_term = get_term_by('name', '未分類', 'category');
    if (!$uncat_term) {
        $uncat_term = get_term_by('name', 'Uncategorized', 'category');
    }
    $default_cat_id = $uncat_term ? $uncat_term->term_id : get_option('default_category');

    // 2. 接收分頁與篩選參數
    $paged = isset($_GET['pg']) ? max(1, intval($_GET['pg'])) : 1;
    
    // 若無傳遞參數(初次載入)，預設為未分類 ID；若參數為空字串，代表選擇「所有分類」(0)
    if (!isset($_GET['filter_cat'])) {
        $filter_cat = $default_cat_id;
    } else {
        $filter_cat = $_GET['filter_cat'] === '' ? 0 : intval($_GET['filter_cat']);
    }

    // 3. 抓取文章 (每頁 50 則)
    $args = [
        'post_type' => 'post',
        'posts_per_page' => 50,
        'post_status' => 'publish',
        'paged' => $paged
    ];

    // 若有選擇分類篩選，加入查詢條件
    if ($filter_cat > 0) {
        $selected_term = get_term($filter_cat, 'category');
        
        // 判斷是否選擇了「未分類」
        if ($selected_term && in_array($selected_term->name, ['未分類', 'Uncategorized'])) {
            $all_cats = get_categories(['hide_empty' => false]);
            $exclude_ids = [];
            
            // 找出所有「非未分類」的其他分類 ID
            foreach ($all_cats as $c) {
                if ($c->term_id != $filter_cat) {
                    $exclude_ids[] = $c->term_id;
                }
            }
            
            // 嚴格篩選：必須包含未分類，且「不得包含」任何其他分類
            $args['category__in'] = [$filter_cat];
            if (!empty($exclude_ids)) {
                $args['category__not_in'] = $exclude_ids;
            }
        } else {
            // 一般分類篩選
            $args['cat'] = $filter_cat;
        }
    }

    $query = new WP_Query($args);

    $nonce = wp_create_nonce('bulk_cat_nonce');
    $ajax_url = admin_url('admin-ajax.php');
    $all_categories = get_categories(['hide_empty' => false]); // 取得所有分類供篩選使用

    ob_start();
    ?>
    <div class="custom-post-grid-wrap" style="font-family: sans-serif; max-width: 1200px; margin: auto;">
        
        <div style="margin-bottom: 15px; padding: 15px; background: #f1f1f1; border-radius: 5px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">
            
            <div style="display: flex; gap: 10px; align-items: center;">
                <strong>大量操作：</strong>
                <select id="bulk-cat-select" style="padding: 5px; border: 1px solid #ccc; border-radius: 3px;">
                    <option value="">-- 選擇分類 --</option>
                    <option value="無相關">無相關</option>
                    <option value="有相關">有相關</option>
                    <option value="有興趣">有興趣</option>
                </select>
                <button id="btn-bulk-apply" style="padding: 6px 15px; background: #007cba; color: #fff; border: none; cursor: pointer; border-radius: 3px;">套用勾選項目</button>
                <span id="bulk-msg" style="margin-left: 10px; font-weight: bold;"></span>
            </div>

            <form method="GET" style="margin: 0; display: flex; gap: 10px; align-items: center;">
                <?php foreach($_GET as $key => $val): if($key !== 'filter_cat' && $key !== 'pg'): ?>
                    <input type="hidden" name="<?php echo esc_attr($key); ?>" value="<?php echo esc_attr($val); ?>">
                <?php endif; endforeach; ?>
                
                <strong>篩選：</strong>
                <select name="filter_cat" style="padding: 5px; border: 1px solid #ccc; border-radius: 3px;" onchange="this.form.submit()">
                    <option value="" <?php selected($filter_cat, 0); ?>>-- 所有分類 --</option>
                    <?php foreach($all_categories as $cat): ?>
                        <option value="<?php echo $cat->term_id; ?>" <?php selected($filter_cat, $cat->term_id); ?>>
                            <?php echo esc_html($cat->name); ?> (<?php echo $cat->count; ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>

        <?php if (!$query->have_posts()): ?>
            <p style="padding: 20px; background: #fff; border: 1px solid #ddd; text-align: center;">目前沒有符合條件的文章。</p>
        <?php else: ?>
            <table style="width: 100%; border-collapse: collapse; text-align: left; border: 1px solid #ddd; background: #fff;">
                <thead>
                    <tr style="background: #007cba; color: white;">
                        <th style="padding: 10px; width: 40px; text-align: center;"><input type="checkbox" id="check-all"></th>
                        <th style="padding: 10px;">文章標題</th>
                        <th style="padding: 10px; width: 180px;">分類</th>
                        <th style="padding: 10px; width: 220px;">快速分類</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($query->have_posts()) : $query->the_post(); $pid = get_the_ID(); ?>
                    <tr id="row-<?php echo $pid; ?>" style="border-bottom: 1px solid #ddd;">
                        <td style="padding: 10px; text-align: center;">
                            <input type="checkbox" class="row-checkbox" value="<?php echo $pid; ?>">
                        </td>
                        <td style="padding: 10px;">
                            <a href="<?php the_permalink(); ?>" target="_blank" style="text-decoration: none; color: #333; font-weight: 500; font-size: 14px;">
                                <?php the_title(); ?>
                            </a>
                        </td>
                        <td style="padding: 10px; font-size: 12px; color: #666;">
                            <?php 
                            // 顯示該文章的所有分類，若有其他分類則隱藏「未分類」
                            $post_categories = get_the_category();
                            if ($post_categories) {
                                $cat_names = wp_list_pluck($post_categories, 'name');
                                if (count($cat_names) > 1) {
                                    $cat_names = array_filter($cat_names, function($name) {
                                        return $name !== '未分類' && $name !== 'Uncategorized';
                                    });
                                }
                                echo esc_html(implode(', ', $cat_names));
                            } else {
                                echo '<span style="color:#bbb;">無分類</span>';
                            }
                            ?>
                        </td>
                        <td style="padding: 10px;">
                            <div style="display: flex; gap: 5px;">
                                <button class="single-cat-btn" data-id="<?php echo $pid; ?>" data-cat="無相關" style="background:#dc3232; color:white; border:none; padding:4px 8px; cursor:pointer; border-radius:3px; font-size:12px;">無相關</button>
                                <button class="single-cat-btn" data-id="<?php echo $pid; ?>" data-cat="有相關" style="background:#f56e28; color:white; border:none; padding:4px 8px; cursor:pointer; border-radius:3px; font-size:12px;">有相關</button>
                                <button class="single-cat-btn" data-id="<?php echo $pid; ?>" data-cat="有興趣" style="background:#46b450; color:white; border:none; padding:4px 8px; cursor:pointer; border-radius:3px; font-size:12px;">有興趣</button>
                            </div>
                            <div class="row-msg" id="msg-<?php echo $pid; ?>" style="font-size: 11px; margin-top: 4px; color: #666;"></div>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>

            <div style="margin-top: 20px; display: flex; justify-content: center; align-items: center; gap: 15px;">
                <?php
                $current_url = remove_query_arg('pg'); // 取得當前網址並移除舊的 pg 參數
                $max_pages = $query->max_num_pages;

                if ($paged > 1) {
                    echo '<a href="' . esc_url(add_query_arg('pg', $paged - 1, $current_url)) . '" style="padding: 6px 15px; background: #fff; border: 1px solid #ccc; text-decoration: none; color: #333; border-radius: 4px; font-size: 14px;">« 上一頁</a>';
                }

                echo '<span style="font-size: 14px; color: #555;">第 <strong>' . $paged . '</strong> / ' . $max_pages . ' 頁</span>';

                if ($paged < $max_pages) {
                    echo '<a href="' . esc_url(add_query_arg('pg', $paged + 1, $current_url)) . '" style="padding: 6px 15px; background: #007cba; border: 1px solid #007cba; text-decoration: none; color: #fff; border-radius: 4px; font-size: 14px;">下一頁 »</a>';
                }
                ?>
            </div>
        <?php endif; wp_reset_postdata(); ?>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const ajaxUrl = '<?php echo $ajax_url; ?>';
        const nonce = '<?php echo $nonce; ?>';

        function sendCategoryRequest(postIds, category, msgElement, rowElement = null) {
            msgElement.innerText = '處理中...';
            msgElement.style.color = '#555';

            fetch(ajaxUrl + '?action=bulk_set_category', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ post_ids: postIds, category: category, security: nonce })
            })
            .then(res => res.json())
            .then(res => {
                if(res.success) {
                    msgElement.style.color = 'green';
                    msgElement.innerText = res.data;
                    
                    // 視覺優化：反綠後淡出移除
                    const removeRow = (row) => {
                        if (!row) return;
                        row.style.transition = 'all 0.5s ease';
                        row.style.background = '#f0fdf4';
                        row.style.opacity = '0';
                        setTimeout(() => row.remove(), 500);
                    };

                    if (rowElement) {
                        removeRow(rowElement); // 單筆移除
                    } else {
                        postIds.forEach(id => removeRow(document.getElementById('row-' + id))); // 批次移除
                    }
                } else {
                    msgElement.style.color = 'red';
                    msgElement.innerText = res.data;
                }
            })
            .catch(() => {
                msgElement.style.color = 'red';
                msgElement.innerText = '網路錯誤';
            });
        }

        const checkAllBtn = document.getElementById('check-all');
        if (checkAllBtn) {
            checkAllBtn.addEventListener('change', function() {
                const isChecked = this.checked;
                document.querySelectorAll('.row-checkbox').forEach(cb => cb.checked = isChecked);
            });
        }

        document.querySelectorAll('.single-cat-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const postId = this.getAttribute('data-id');
                const category = this.getAttribute('data-cat');
                const msgBox = document.getElementById('msg-' + postId);
                const row = document.getElementById('row-' + postId);
                sendCategoryRequest([postId], category, msgBox, row);
            });
        });

        const bulkBtn = document.getElementById('btn-bulk-apply');
        if (bulkBtn) {
            bulkBtn.addEventListener('click', function() {
                const selectedCat = document.getElementById('bulk-cat-select').value;
                const bulkMsgBox = document.getElementById('bulk-msg');
                
                if(!selectedCat) {
                    bulkMsgBox.style.color = 'red'; bulkMsgBox.innerText = '請先選擇分類'; return;
                }

                const checkedBoxes = document.querySelectorAll('.row-checkbox:checked');
                if(checkedBoxes.length === 0) {
                    bulkMsgBox.style.color = 'red'; bulkMsgBox.innerText = '請至少勾選一篇文章'; return;
                }

                const postIds = Array.from(checkedBoxes).map(cb => cb.value);
                sendCategoryRequest(postIds, selectedCat, bulkMsgBox);
            });
        }
    });
    </script>
    <?php
    return ob_get_clean();
}
