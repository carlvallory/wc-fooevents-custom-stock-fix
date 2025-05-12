<?php
/** 
* Plugin Name: FooEvents Auto Refund for Canceled Orders
* Description: Cambia automáticamente el estado de pedidos cancelados a devueltos si contienen entradas de FooEvents.
* Version: 2.1
* Author: Carlos Vallory
* Developer: Carlos Vallory
* License: GPL-3.0+
* License URI: https://www.gnu.org/licenses/gpl-3.0.txt
* Requires plugin: woocommerce
* WP stable tag: 6.5.0
* WP requires at least: 6.5.0
* WP tested up to: 6.8.1
* WC requires at least: 9.2.0
* WC tested up to: 9.8.4
*/

add_action('woocommerce_order_status_changed', 'convertir_cancelado_a_devuelto_si_es_evento_v2', 10, 4);

function convertir_cancelado_a_devuelto_si_es_evento_v2($order_id, $old_status, $new_status, $order) {
    // Solo actuar si el nuevo estado es "cancelled"
    if ('cancelled' === $new_status) {
        $tiene_eventos = false;
        foreach ($order->get_items() as $item) {
            if (get_post_meta($item->get_product_id(), '_eventmagic_event', true)) {
                $tiene_eventos = true;
                break;
            }
        }
        if ($tiene_eventos) {
            $order->update_status('refunded', 'Cambio automático de cancelado a devuelto.');
        }
    }
}
