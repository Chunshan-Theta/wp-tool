<?php
// 註冊 AJAX 端點 (開放給「已登入」與「未登入」使用者)
add_action('wp_ajax_bulk_set_category', 'handle_bulk_set_category');
add_action('wp_ajax_nopriv_bulk_set_category', 'handle_bulk_set_category'); // 新增此行解鎖未登入權限

function handle_bulk_set_category() {
    // 接收 JSON 格式的 payload
    $request_body = file_get_contents('php://input');
    $data = json_decode($request_body, true);

    if (empty($data['security']) || !wp_verify_nonce($data['security'], 'bulk_cat_nonce')) {
        wp_send_json_error('驗證失敗，請重新整理頁面。');
    }

    $post_ids = isset($data['post_ids']) ? (array) $data['post_ids'] : [];
    $category = isset($data['category']) ? sanitize_text_field($data['category']) : '';

    if (empty($post_ids) || empty($category)) {
        wp_send_json_error('缺少必要資料 (文章 ID 或分類名稱)。');
    }

    $success_count = 0;
    foreach ($post_ids as $post_id) {
        $post_id = intval($post_id);
        if ($post_id > 0) {
            // 寫入分類 (true 代表附加不覆蓋)
            $result = wp_set_object_terms($post_id, $category, 'category', true);
            if (!is_wp_error($result)) {
                $success_count++;
            }
        }
    }

    wp_send_json_success("成功更新 {$success_count} 篇文章為「{$category}」。");
}
