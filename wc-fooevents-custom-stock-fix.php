<?php
/** 
* Plugin Name: FooEvents Auto Refund for Canceled Orders
* Description: Cambia automáticamente el estado de pedidos cancelados a devueltos si contienen entradas de FooEvents.
* Version: 1.0
* Author: Carlos Vallory
*/

add_action('woocommerce_order_status_cancelled', 'convertir_cancelado_a_devuelto_si_es_evento');

function convertir_cancelado_a_devuelto_si_es_evento($order_id) {
    $order = wc_get_order($order_id);
    $tiene_eventos = false;

    foreach ($order->get_items() as $item) {
        $product_id = $item->get_product_id();
        if (get_post_meta($product_id, '_eventmagic_event', true)) {
            $tiene_eventos = true;
            break;
        }
    }

    if ($tiene_eventos && $order->get_status() == 'cancelled') {
        $order->update_status('refunded', 'Cambio automático desde cancelado para liberar entradas.');
    }
}
