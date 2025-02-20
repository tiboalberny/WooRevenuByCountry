<?php
/**
 * Plugin Name: WooCommerce Revenue Report by Country
 * Description: Un plugin pour afficher les revenus WooCommerce par pays et période.
 * Version: 1.5
 * Author: Tibo le Maître
 */

// Sécurité : Empêcher un accès direct
if (!defined('ABSPATH')) {
    exit;
}

// Ajouter un menu dans l'admin WordPress
add_action('admin_menu', 'wcrr_add_admin_menu');
function wcrr_add_admin_menu() {
    add_menu_page('Revenus WooCommerce', 'Revenus Woo', 'manage_options', 'wcrr-report', 'wcrr_display_admin_page', 'dashicons-chart-bar', 56);
}

// Vérifier si l'utilisateur a demandé un export CSV
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['wcrr_export_csv'])) {
    wcrr_export_csv();
    exit();
}

// Fonction d'export CSV
function wcrr_export_csv() {
    global $wpdb;
    
    $country = isset($_POST['wcrr_country']) ? sanitize_text_field($_POST['wcrr_country']) : 'FR';
    $month = isset($_POST['wcrr_month']) ? sanitize_text_field($_POST['wcrr_month']) : date('Y-m');
    
    $start_date = new DateTime($month . '-01');
    $end_date = new DateTime($month . '-01');
    $end_date->modify('last day of this month');
    
    // Générer toutes les dates du mois
    $dates = [];
    $period = new DatePeriod(
        $start_date,
        new DateInterval('P1D'),
        (clone $end_date)->modify('+1 day')
    );
    foreach ($period as $date) {
        $dates[$date->format('Y-m-d')] = [
            'total_orders' => 0,
            'total_excl_tax' => 0,
            'total_tax' => 0,
            'total_revenue' => 0,
            'total_shipping' => 0
        ];
    }
    
    // Récupérer les données réelles
    $results = $wpdb->get_results($wpdb->prepare(
        "SELECT DATE(p.post_date) AS order_date, COUNT(p.ID) AS total_orders,
                COALESCE(SUM(oim.meta_value - tax.meta_value), 0) AS total_excl_tax,
                COALESCE(SUM(tax.meta_value), 0) AS total_tax,
                COALESCE(SUM(oim.meta_value), 0) AS total_revenue,
                COALESCE(SUM(shipping.meta_value), 0) AS total_shipping
        FROM {$wpdb->prefix}posts p
        JOIN {$wpdb->prefix}postmeta pm ON p.ID = pm.post_id AND pm.meta_key = '_billing_country'
        JOIN {$wpdb->prefix}postmeta oim ON p.ID = oim.post_id AND oim.meta_key = '_order_total'
        JOIN {$wpdb->prefix}postmeta tax ON p.ID = tax.post_id AND tax.meta_key = '_order_tax'
        LEFT JOIN {$wpdb->prefix}postmeta shipping ON p.ID = shipping.post_id AND shipping.meta_key = '_order_shipping'
        WHERE p.post_type = 'shop_order'
            AND p.post_status IN ('wc-completed', 'wc-processing', 'wc-on-hold') AND p.post_status NOT IN ('wc-cancelled', 'wc-failed')
            AND pm.meta_value = %s
            AND p.post_date BETWEEN %s AND %s
        GROUP BY order_date
        ORDER BY order_date ASC",
        $country, $start_date->format('Y-m-d 00:00:00'), $end_date->format('Y-m-d 23:59:59')
    ));
    
    // Injecter les résultats dans le tableau des dates
    foreach ($results as $row) {
        if (isset($dates[$row->order_date])) {
            $dates[$row->order_date] = [
                'total_orders' => $row->total_orders,
                'total_excl_tax' => $row->total_excl_tax,
                'total_tax' => $row->total_tax,
                'total_revenue' => $row->total_revenue,
                'total_shipping' => $row->total_shipping
            ];
        }
    }
    
    // Préparer l'export CSV
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="revenus_woocommerce_' . $country . '.csv"');
    header("Pragma: no-cache");
    header("Expires: 0");
    
    $output = fopen('php://output', 'w');
    if ($output === false) {
        die('Erreur lors de l\'ouverture du flux de sortie.');
    }
    fputcsv($output, ['Date', 'Nombre de Commandes', 'Chiffre d\'Affaires HT', 'Taxes Totales', 'Revenu Total', 'Frais de Port']);
    
    foreach ($dates as $date => $data) {
        fputcsv($output, [
            $date,
            $data['total_orders'],
            number_format($data['total_excl_tax'], 2, '.', ''),
            number_format($data['total_tax'], 2, '.', ''),
            number_format($data['total_revenue'], 2, '.', ''),
            number_format($data['total_shipping'], 2, '.', '')
        ]);
    }
    fclose($output);
    exit();
}

function wcrr_display_admin_page() {
    global $wpdb;

    $countries = [
        'FR' => 'France',
        'BE' => 'Belgique',
        'LU' => 'Luxembourg',
        'CH' => 'Suisse'
    ];
    
    $country = isset($_POST['wcrr_country']) ? sanitize_text_field($_POST['wcrr_country']) : 'FR';
    $month = isset($_POST['wcrr_month']) ? sanitize_text_field($_POST['wcrr_month']) : date('Y-m');
    
    echo '<div class="wrap"><h1>Rapport de revenus WooCommerce</h1>';
    echo '<form method="post">';
    echo 'Pays : <select name="wcrr_country">';
    foreach ($countries as $code => $name) {
        echo '<option value="' . esc_attr($code) . '"' . selected($country, $code, false) . '>' . esc_html($name) . '</option>';
    }
    echo '</select> ';
    echo 'Mois : <input type="month" name="wcrr_month" value="' . esc_attr($month) . '"> ';
    echo '<input type="submit" value="Filtrer" class="button button-primary">';
    echo '<input type="submit" name="wcrr_export_csv" value="Exporter CSV" class="button button-secondary">';
    echo '</form><br>';
    global $wpdb;

    $country = isset($_POST['wcrr_country']) ? sanitize_text_field($_POST['wcrr_country']) : 'FR';
    $month = isset($_POST['wcrr_month']) ? sanitize_text_field($_POST['wcrr_month']) : date('Y-m');
    
    $start_date = new DateTime($month . '-01');
    $end_date = new DateTime($month . '-01');
    $end_date->modify('last day of this month');
    
    // Générer toutes les dates du mois
    $dates = [];
    $period = new DatePeriod(
        $start_date,
        new DateInterval('P1D'),
        (clone $end_date)->modify('+1 day')
    );
    foreach ($period as $date) {
        $dates[$date->format('Y-m-d')] = [
            'total_orders' => 0,
            'total_excl_tax' => 0,
            'total_tax' => 0,
            'total_revenue' => 0,
            'total_shipping' => 0
        ];
    }
    
    // Récupérer les données réelles
    $results = $wpdb->get_results($wpdb->prepare(
        "SELECT DATE(p.post_date) AS order_date, COUNT(p.ID) AS total_orders,
                COALESCE(SUM(oim.meta_value - tax.meta_value), 0) AS total_excl_tax,
                COALESCE(SUM(tax.meta_value), 0) AS total_tax,
                COALESCE(SUM(oim.meta_value), 0) AS total_revenue,
                COALESCE(SUM(shipping.meta_value), 0) AS total_shipping
        FROM {$wpdb->prefix}posts p
        JOIN {$wpdb->prefix}postmeta pm ON p.ID = pm.post_id AND pm.meta_key = '_billing_country'
        JOIN {$wpdb->prefix}postmeta oim ON p.ID = oim.post_id AND oim.meta_key = '_order_total'
        JOIN {$wpdb->prefix}postmeta tax ON p.ID = tax.post_id AND tax.meta_key = '_order_tax'
        LEFT JOIN {$wpdb->prefix}postmeta shipping ON p.ID = shipping.post_id AND shipping.meta_key = '_order_shipping'
        WHERE p.post_type = 'shop_order'
            AND p.post_status IN ('wc-completed', 'wc-processing', 'wc-on-hold')
            AND pm.meta_value = %s
            AND p.post_date BETWEEN %s AND %s
        GROUP BY order_date
        ORDER BY order_date ASC",
        $country, $start_date->format('Y-m-d 00:00:00'), $end_date->format('Y-m-d 23:59:59')
    ));
    
    // Injecter les résultats dans le tableau des dates
    foreach ($results as $row) {
        if (isset($dates[$row->order_date])) {
            $dates[$row->order_date] = [
                'total_orders' => $row->total_orders,
                'total_excl_tax' => $row->total_excl_tax,
                'total_tax' => $row->total_tax,
                'total_revenue' => $row->total_revenue,
                'total_shipping' => $row->total_shipping
            ];
        }
    }
    
    echo '<div class="wrap"><h1>Rapport de revenus WooCommerce</h1>';
    echo '<table class="widefat fixed">';
    echo '<thead><tr><th>Date</th><th>Nombre de Commandes</th><th>Chiffre d\'Affaires HT</th><th>Taxes Totales</th><th>Revenu Total</th><th>Frais de Port</th></tr></thead><tbody>';
    foreach ($dates as $date => $data) {
        echo '<tr><td>' . esc_html($date) . '</td>';
        echo '<td>' . esc_html($data['total_orders']) . '</td>';
        echo '<td>' . esc_html(number_format($data['total_excl_tax'], 2)) . ' €</td>';
        echo '<td>' . esc_html(number_format($data['total_tax'], 2)) . ' €</td>';
        echo '<td>' . esc_html(number_format($data['total_revenue'], 2)) . ' €</td>';
        echo '<td>' . esc_html(number_format($data['total_shipping'], 2)) . ' €</td></tr>';
    }
    echo '</tbody></table>';
    echo '</div>';
}
