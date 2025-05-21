<?php
/** 
* Plugin Name: FooEvents Auto Refund for Canceled Orders
* Description: Cambia automáticamente el estado de pedidos cancelados a devueltos si contienen entradas de FooEvents.
* Version: 2.1.1
* Author: Carlos Vallory
* Developer: Carlos Vallory
* Requires Plugins: woocommerce
*
* Requires at least: 6.1
* Tested up to: 6.7
* Requires PHP: 7.4
* WC requires at least: 7.9
* WC tested up to: 9.6
*
* License: GNU General Public License v3.0
* License URI: http://www.gnu.org/licenses/gpl-3.0.html
*/

define('ENTRADAS_MAXIMAS_POR_EVENTO', 30);

add_action('woocommerce_order_status_changed', 'convertir_cancelado_a_devuelto_si_es_evento_v2', 10, 4);

function convertir_cancelado_a_devuelto_si_es_evento_v2($order_id, $old_status, $new_status, $order) {
    // Solo actuar si el nuevo estado es "cancelled"
    if ('pending payment' === $new_status) {

    }
    if ('processing' === $new_status) {

    }
    if ('completed' === $new_status) {

    }
    if ('cancelled' === $new_status) {
        $tiene_eventos = false;
        foreach ($order->get_items() as $item) {
            $product_id = $item->get_product_id();
            if (get_post_meta($product_id, '_eventmagic_event', true)) {
                $tiene_eventos = true;
                break;
            }
        }
        if ($tiene_eventos) {
            $order->update_status('Refunded', 'Automatic change from cancelled to refunded.');
        }
    }
    if ('refunded' === $new_status) {
        
    }
}

function ajustar_stock($product_id) {
    if ('yes' === get_post_meta($product_id, '_manage_stock', true)) {
        // Contar Entradas vendidas
        $entradas_vendidas = count(get_posts([
            'post_type'   => 'event_magic_tickets',
            'post_status' => 'publish',
            'meta_key'    => 'fooevents_product_id',
            'meta_value'  => $product_id,
            'fields'      => 'ids',
            'numberposts' => -1
        ]));

        // Stock máximo permitido por evento
        $stock_correcto = max(ENTRADAS_MAXIMAS_POR_EVENTO - $entradas_vendidas, 0);

        // Stock actual
        $stock_actual = (int) get_post_meta($product_id, '_stock', true);

        // Solo actualiza si hay diferencia
        if ($stock_actual !== $stock_correcto) {
            update_post_meta($product_id, '_stock', $stock_correcto);
            error_log("Stock corregido para producto $product_id: vendidas=$entradas_vendidas, stock_actual=$stock_actual, nuevo_stock=$stock_correcto");
        }
    } else {
        return;
    }
}
