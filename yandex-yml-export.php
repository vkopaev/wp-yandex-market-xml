<?php
/**
 * Plugin Name: XML-page generator for Yandex Market format YML
 * Plugin URI: https://github.com/vkopaev/wp-yandex-market-xml
 * Description: Плагин для экспорта товаров в формате XML для Яндекс.Маркета
 * Version: 1.0.0
 * Author: vkopaev
 * License: GPL v2 or later
 * Text Domain: xml-page-generator-for-yandex-market-format-yml
 */

// Запрет прямого доступа
if (!defined('ABSPATH')) {
    exit;
}

class YandexXMLExport {
    
    private static $instance = null;
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('init', array($this, 'register_endpoint'));
        add_filter('query_vars', array($this, 'add_query_var'));
        add_action('template_redirect', array($this, 'handle_xml_export'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_notices', array($this, 'admin_notices'));
        
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
    }
    
    public function activate() {
        $this->register_endpoint();
        flush_rewrite_rules();
        add_option('yandex_xml_need_flush', true);
    }
    
    public function deactivate() {
        flush_rewrite_rules();
    }
    
    public function register_endpoint() {
        add_rewrite_rule(
            '^api/integrations/yandex/items/?$',
            'index.php?yandex_xml_export=1',
            'top'
        );
    }
    
    public function add_query_var($vars) {
        $vars[] = 'yandex_xml_export';
        return $vars;
    }
    
    public function handle_xml_export() {
        if (get_query_var('yandex_xml_export')) {
            // Получаем настройки
            $post_type = get_option('yandex_xml_post_type', 'product');
            $taxonomy = get_option('yandex_xml_taxonomy', 'category');
            $shop_name = get_option('yandex_xml_shop_name', get_bloginfo('name'));
            $company = get_option('yandex_xml_company', get_bloginfo('name'));
            $platform = get_option('yandex_xml_platform', 'WordPress');
            $delivery_cost = get_option('yandex_xml_delivery_cost', '200');
            $delivery_days = get_option('yandex_xml_delivery_days', '1');
            $currency = get_option('yandex_xml_currency', 'RUR');
            $enable_delivery = get_option('yandex_xml_enable_delivery', '1');
            
            // Получаем все записи выбранного типа
            $args = array(
                'post_type' => $post_type,
                'posts_per_page' => -1,
                'post_status' => 'publish',
                'orderby' => 'title',
                'order' => 'ASC'
            );
            
            $posts_query = new WP_Query($args);
            
            // Устанавливаем заголовки для XML
            header('Content-Type: application/xml; charset=utf-8');
            header('Content-Disposition: inline; filename="yandex-export.xml"');
            
            // Начинаем вывод XML
            echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
            echo '<yml_catalog date="' . date('Y-m-d\TH:i:sP') . '">' . "\n";
            echo '  <shop>' . "\n";
            echo '      <name>' . $this->escape_xml($shop_name) . '</name>' . "\n";
            echo '      <company>' . $this->escape_xml($company) . '</company>' . "\n";
            echo '      <url>' . $this->escape_xml(home_url()) . '</url>' . "\n";
            echo '      <platform>' . $this->escape_xml($platform) . '</platform>' . "\n";
            
            // Выводим категории из выбранной таксономии
            echo '      <categories>' . "\n";
            $categories = get_terms(array(
                'taxonomy' => $taxonomy,
                'hide_empty' => true,
                'orderby' => 'name',
                'order' => 'ASC'
            ));
            
            if (!is_wp_error($categories) && !empty($categories)) {
                foreach ($categories as $category) {
                    $parent = $category->parent ? ' parentId="' . $category->parent . '"' : '';
                    echo '          <category id="' . $category->term_id . '"' . $parent . '>' . 
                         $this->escape_xml($category->name) . '</category>' . "\n";
                }
            }
            echo '      </categories>' . "\n";
            
            // Опции доставки (только если включено)
            if ($enable_delivery) {
                echo '      <delivery-options>' . "\n";
                echo '          <option cost="' . $delivery_cost . '" days="' . $delivery_days . '"/>' . "\n";
                echo '      </delivery-options>' . "\n";
                
                // Опции самовывоза
                echo '      <pickup-options>' . "\n";
                echo '          <option cost="' . $delivery_cost . '" days="' . $delivery_days . '"/>' . "\n";
                echo '      </pickup-options>' . "\n";
            }
            
            // Предложения (offers)
            echo '      <offers>' . "\n";
            
            if ($posts_query->have_posts()) {
                while ($posts_query->have_posts()) {
                    $posts_query->the_post();
                    
                    $post_id = get_the_ID();
                    
                    // Получаем термины из выбранной таксономии
                    $terms = get_the_terms($post_id, $taxonomy);
                    $category_id = 1; // значение по умолчанию
                    
                    if (!is_wp_error($terms) && !empty($terms)) {
                        $first_term = reset($terms);
                        $category_id = $first_term->term_id;
                    }
                    
                    // Получаем произвольные поля
                    $price = get_post_meta($post_id, '_price', true);
                    $old_price = get_post_meta($post_id, '_old_price', true);
                    $vendor = get_post_meta($post_id, '_vendor', true);
                    $vendor_code = get_post_meta($post_id, '_vendor_code', true);
                    $barcode = get_post_meta($post_id, '_barcode', true);
                    $sales_notes = get_post_meta($post_id, '_sales_notes', true);
                    $weight = get_post_meta($post_id, '_weight', true);
                    $dimensions = get_post_meta($post_id, '_dimensions', true);
                    
                    echo '          <offer id="' . $post_id . '">' . "\n";
                    echo '              <name>' . $this->escape_xml(get_the_title()) . '</name>' . "\n";
                    
                    if ($vendor) {
                        echo '              <vendor>' . $this->escape_xml($vendor) . '</vendor>' . "\n";
                    }
                    
                    if ($vendor_code) {
                        echo '              <vendorCode>' . $this->escape_xml($vendor_code) . '</vendorCode>' . "\n";
                    }
                    
                    echo '              <url>' . $this->escape_xml(get_permalink()) . '</url>' . "\n";
                    
                    if ($price) {
                        echo '              <price>' . $this->escape_xml($price) . '</price>' . "\n";
                    }
                    
                    if ($old_price) {
                        echo '              <oldprice>' . $this->escape_xml($old_price) . '</oldprice>' . "\n";
                    }
                    
                    echo '              <enable_auto_discounts>true</enable_auto_discounts>' . "\n";
                    echo '              <currencyId>' . $currency . '</currencyId>' . "\n";
                    echo '              <categoryId>' . $category_id . '</categoryId>' . "\n";
                    
                    // Изображение
                    if (has_post_thumbnail()) {
                        $image_url = get_the_post_thumbnail_url($post_id, 'full');
                        echo '              <picture>' . $this->escape_xml($image_url) . '</picture>' . "\n";
                    }
                    
                    // Описание
                    $description = get_the_content();
                    if ($description) {
                        echo '              <description><![CDATA[' . $description . ']]></description>' . "\n";
                    }
                    
                    if ($sales_notes) {
                        echo '              <sales_notes>' . $this->escape_xml($sales_notes) . '</sales_notes>' . "\n";
                    }
                    
                    echo '              <manufacturer_warranty>true</manufacturer_warranty>' . "\n";
                    
                    if ($barcode) {
                        echo '              <barcode>' . $this->escape_xml($barcode) . '</barcode>' . "\n";
                    }
                    
                    // Дополнительные параметры
                    $color = get_post_meta($post_id, '_color', true);
                    if ($color) {
                        echo '              <param name="Цвет">' . $this->escape_xml($color) . '</param>' . "\n";
                    }
                    
                    if ($weight) {
                        echo '              <weight>' . $this->escape_xml($weight) . '</weight>' . "\n";
                    }
                    
                    if ($dimensions) {
                        echo '              <dimensions>' . $this->escape_xml($dimensions) . '</dimensions>' . "\n";
                    }
                    
                    // echo '              <condition type="new"/>' . "\n";
                    
                    echo '          </offer>' . "\n";
                }
                wp_reset_postdata();
            }
            
            echo '      </offers>' . "\n";
            echo '  </shop>' . "\n";
            echo '</yml_catalog>';
            
            exit;
        }
    }
    
    private function escape_xml($string) {
        return htmlspecialchars($string, ENT_XML1, 'UTF-8');
    }
    
    // Функции санитизации для настроек
    public function sanitize_post_type($input) {
        $post_types = get_post_types(array('public' => true));
        return in_array($input, $post_types) ? $input : 'product';
    }
    
    public function sanitize_taxonomy($input) {
        $taxonomies = get_taxonomies(array('public' => true));
        return in_array($input, $taxonomies) ? $input : 'category';
    }
    
    public function sanitize_text($input) {
        return sanitize_text_field($input);
    }
    
    public function sanitize_number($input) {
        return absint($input);
    }
    
    public function sanitize_currency($input) {
        $allowed_currencies = array('RUR', 'RUB', 'USD', 'EUR');
        return in_array($input, $allowed_currencies) ? $input : 'RUR';
    }
    
    public function sanitize_checkbox($input) {
        return $input ? '1' : '0';
    }
    
    // Получаем все таксономии для выбранного типа записей
    private function get_taxonomies_for_post_type($post_type) {
        $taxonomies = get_object_taxonomies($post_type, 'objects');
        $available_taxonomies = array();
        
        foreach ($taxonomies as $taxonomy) {
            if ($taxonomy->public && $taxonomy->show_ui) {
                $available_taxonomies[$taxonomy->name] = $taxonomy->label;
            }
        }
        
        return $available_taxonomies;
    }
    
    public function add_admin_menu() {
        add_options_page(
            'Настройки Яндекс XML',
            'Яндекс XML',
            'manage_options',
            'yandex-xml-export',
            array($this, 'settings_page')
        );
    }
    
    public function register_settings() {
        register_setting(
            'yandex_xml_settings', 
            'yandex_xml_post_type', 
            array(
                'sanitize_callback' => array($this, 'sanitize_post_type')
            )
        );
        
        register_setting(
            'yandex_xml_settings', 
            'yandex_xml_taxonomy', 
            array(
                'sanitize_callback' => array($this, 'sanitize_taxonomy')
            )
        );
        
        register_setting(
            'yandex_xml_settings', 
            'yandex_xml_shop_name', 
            array(
                'sanitize_callback' => array($this, 'sanitize_text')
            )
        );
        
        register_setting(
            'yandex_xml_settings', 
            'yandex_xml_company', 
            array(
                'sanitize_callback' => array($this, 'sanitize_text')
            )
        );
        
        register_setting(
            'yandex_xml_settings', 
            'yandex_xml_platform', 
            array(
                'sanitize_callback' => array($this, 'sanitize_text')
            )
        );
        
        register_setting(
            'yandex_xml_settings', 
            'yandex_xml_delivery_cost', 
            array(
                'sanitize_callback' => array($this, 'sanitize_number')
            )
        );
        
        register_setting(
            'yandex_xml_settings', 
            'yandex_xml_delivery_days', 
            array(
                'sanitize_callback' => array($this, 'sanitize_number')
            )
        );
        
        register_setting(
            'yandex_xml_settings', 
            'yandex_xml_currency', 
            array(
                'sanitize_callback' => array($this, 'sanitize_currency')
            )
        );
        
        register_setting(
            'yandex_xml_settings', 
            'yandex_xml_enable_delivery', 
            array(
                'sanitize_callback' => array($this, 'sanitize_checkbox')
            )
        );
    }
    
    public function settings_page() {
        $current_post_type = get_option('yandex_xml_post_type', 'product');
        $available_taxonomies = $this->get_taxonomies_for_post_type($current_post_type);
        ?>
        <div class="wrap">
            <h1>Настройки Яндекс XML Экспорт</h1>
            
            <form method="post" action="options.php">
                <?php settings_fields('yandex_xml_settings'); ?>
                <?php do_settings_sections('yandex_xml_settings'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">Тип записей для вывода</th>
                        <td>
                            <select name="yandex_xml_post_type" id="yandex_xml_post_type" style="min-width: 200px;">
                                <?php
                                $post_types = get_post_types(array('public' => true), 'objects');
                                $selected_type = $current_post_type;
                                
                                foreach ($post_types as $post_type) {
                                    if ($post_type->name != 'attachment') {
                                        $selected = ($post_type->name == $selected_type) ? 'selected' : '';
                                        echo '<option value="' . esc_attr($post_type->name) . '" ' . $selected . '>' . esc_html($post_type->label) . '</option>';
                                    }
                                }
                                ?>
                            </select>
                            <p class="description">Выберите тип записей, которые будут экспортироваться в XML</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Таксономия для категорий</th>
                        <td>
                            <select name="yandex_xml_taxonomy" id="yandex_xml_taxonomy" style="min-width: 200px;">
                                <?php
                                $selected_taxonomy = get_option('yandex_xml_taxonomy', 'category');
                                
                                if (!empty($available_taxonomies)) {
                                    foreach ($available_taxonomies as $tax_name => $tax_label) {
                                        $selected = ($tax_name == $selected_taxonomy) ? 'selected' : '';
                                        echo '<option value="' . esc_attr($tax_name) . '" ' . $selected . '>' . esc_html($tax_label) . '</option>';
                                    }
                                } else {
                                    echo '<option value="category">Категории</option>';
                                }
                                ?>
                            </select>
                            <p class="description">Выберите таксономию, которая будет использоваться для категорий в XML фиде</p>
                            <div id="taxonomy-info" style="margin-top: 5px; font-size: 12px; color: #666;">
                                <?php
                                if (!empty($available_taxonomies)) {
                                    echo 'Доступно таксономий: ' . count($available_taxonomies);
                                } else {
                                    echo 'Для выбранного типа записей таксономии не найдены';
                                }
                                ?>
                            </div>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Название магазина</th>
                        <td>
                            <input type="text" name="yandex_xml_shop_name" value="<?php echo esc_attr(get_option('yandex_xml_shop_name', get_bloginfo('name'))); ?>" class="regular-text" />
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Название компании</th>
                        <td>
                            <input type="text" name="yandex_xml_company" value="<?php echo esc_attr(get_option('yandex_xml_company', get_bloginfo('name'))); ?>" class="regular-text" />
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Платформа</th>
                        <td>
                            <input type="text" name="yandex_xml_platform" value="<?php echo esc_attr(get_option('yandex_xml_platform', 'WordPress')); ?>" class="regular-text" />
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Включить доставку</th>
                        <td>
                            <label>
                                <input type="checkbox" name="yandex_xml_enable_delivery" value="1" <?php checked(get_option('yandex_xml_enable_delivery', '1'), '1'); ?> />
                                Включить блоки доставки и самовывоза в XML
                            </label>
                            <p class="description">Если отключено, блоки delivery-options и pickup-options не будут включены в экспорт</p>
                        </td>
                    </tr>
                    
                    <tr class="delivery-settings">
                        <th scope="row">Стоимость доставки</th>
                        <td>
                            <input type="number" name="yandex_xml_delivery_cost" value="<?php echo esc_attr(get_option('yandex_xml_delivery_cost', '200')); ?>" class="small-text" min="0" step="1" />
                            <p class="description">Стоимость доставки в рублях</p>
                        </td>
                    </tr>
                    
                    <tr class="delivery-settings">
                        <th scope="row">Срок доставки (дни)</th>
                        <td>
                            <input type="number" name="yandex_xml_delivery_days" value="<?php echo esc_attr(get_option('yandex_xml_delivery_days', '1')); ?>" class="small-text" min="1" step="1" />
                            <p class="description">Срок доставки в рабочих днях</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Валюта</th>
                        <td>
                            <select name="yandex_xml_currency" style="min-width: 100px;">
                                <option value="RUR" <?php selected(get_option('yandex_xml_currency', 'RUR'), 'RUR'); ?>>RUR</option>
                                <option value="RUB" <?php selected(get_option('yandex_xml_currency', 'RUR'), 'RUB'); ?>>RUB</option>
                                <option value="USD" <?php selected(get_option('yandex_xml_currency', 'RUR'), 'USD'); ?>>USD</option>
                                <option value="EUR" <?php selected(get_option('yandex_xml_currency', 'RUR'), 'EUR'); ?>>EUR</option>
                            </select>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button(); ?>
            </form>
            
            <div class="card">
                <h2>Информация</h2>
                <p><strong>URL для Яндекс:</strong> <code><?php echo home_url('/api/integrations/yandex/items'); ?></code></p>
                <p><strong>Произвольные поля для товаров:</strong></p>
                <ul>
                    <li><code>_price</code> - цена</li>
                    <li><code>_old_price</code> - старая цена</li>
                    <li><code>_vendor</code> - производитель</li>
                    <li><code>_vendor_code</code> - артикул</li>
                    <li><code>_barcode</code> - штрихкод</li>
                    <li><code>_sales_notes</code> - условия продажи</li>
                    <li><code>_weight</code> - вес</li>
                    <li><code>_dimensions</code> - габариты</li>
                    <li><code>_color</code> - цвет</li>
                </ul>
                <p>После сохранения настроек не забудьте пересохранить постоянные ссылки в <a href="<?php echo admin_url('options-permalink.php'); ?>">настройках</a>.</p>
            </div>
        </div>
        
        <style>
            .card {
                background: #fff;
                border: 1px solid #ccd0d4;
                border-radius: 4px;
                padding: 20px;
                margin: 20px 0;
            }
            .card h2 {
                margin-top: 0;
            }
            code {
                background: #f1f1f1;
                padding: 2px 4px;
                border-radius: 3px;
            }
            .delivery-settings {
                background: #f9f9f9;
                transition: all 0.3s ease;
            }
        </style>
        
        <script>
        jQuery(document).ready(function($) {
            function toggleDeliverySettings() {
                var isEnabled = $('input[name="yandex_xml_enable_delivery"]').is(':checked');
                $('.delivery-settings').toggle(isEnabled);
            }
            
            // Функция для обновления списка таксономий
            function updateTaxonomies() {
                var postType = $('#yandex_xml_post_type').val();
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'yandex_xml_get_taxonomies',
                        post_type: postType,
                        nonce: '<?php echo wp_create_nonce('yandex_xml_nonce'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            $('#yandex_xml_taxonomy').html(response.data.options);
                            $('#taxonomy-info').html(response.data.info);
                        }
                    }
                });
            }
            
            // Инициализация при загрузке
            toggleDeliverySettings();
            
            // Обработчик изменения чексбокса
            $('input[name="yandex_xml_enable_delivery"]').change(function() {
                toggleDeliverySettings();
            });
            
            // Обработчик изменения типа записей
            $('#yandex_xml_post_type').change(function() {
                updateTaxonomies();
            });
        });
        </script>
        <?php
    }
    
    // AJAX обработчик для получения таксономий
    public function ajax_get_taxonomies() {
        check_ajax_referer('yandex_xml_nonce', 'nonce');
        
        $post_type = sanitize_text_field($_POST['post_type']);
        $taxonomies = $this->get_taxonomies_for_post_type($post_type);
        
        $options = '';
        $info = '';
        
        if (!empty($taxonomies)) {
            foreach ($taxonomies as $tax_name => $tax_label) {
                $options .= '<option value="' . esc_attr($tax_name) . '">' . esc_html($tax_label) . '</option>';
            }
            $info = 'Доступно таксономий: ' . count($taxonomies);
        } else {
            $options = '<option value="category">Категории</option>';
            $info = 'Для выбранного типа записей таксономии не найдены';
        }
        
        wp_send_json_success(array(
            'options' => $options,
            'info' => $info
        ));
    }
    
    public function admin_notices() {
        if (get_option('yandex_xml_need_flush')) {
            ?>
            <div class="notice notice-warning is-dismissible">
                <p>Для работы Яндекс XML экспорта необходимо <a href="<?php echo admin_url('options-permalink.php'); ?>">пересохранить постоянные ссылки</a>.</p>
            </div>
            <?php
            delete_option('yandex_xml_need_flush');
        }
    }
}

// Инициализация плагина
$yandex_xml_export = YandexXMLExport::getInstance();

// Регистрируем AJAX обработчики
add_action('wp_ajax_yandex_xml_get_taxonomies', array($yandex_xml_export, 'ajax_get_taxonomies'));

// Хук для сброса правил при изменении настроек
function yandex_xml_settings_updated() {
    flush_rewrite_rules();
    update_option('yandex_xml_need_flush', true);
}
add_action('update_option_yandex_xml_post_type', 'yandex_xml_settings_updated');
add_action('update_option_yandex_xml_taxonomy', 'yandex_xml_settings_updated');
?>