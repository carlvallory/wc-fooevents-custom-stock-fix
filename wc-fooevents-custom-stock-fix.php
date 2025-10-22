<?php
/** 
* Plugin Name: FooEvents Auto Refund for Canceled Orders
* Description: Cambia automáticamente el estado de pedidos cancelados a devueltos si contienen entradas de FooEvents Y fueron pagados.
* Version: 3.0.0
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

// Constante solo para productos simples con gestión WooCommerce
define('ENTRADAS_MAXIMAS_POR_EVENTO', 30);

add_action('woocommerce_order_status_changed', 'convertir_cancelado_a_devuelto_si_es_evento_v2', 10, 4);

function convertir_cancelado_a_devuelto_si_es_evento_v2($order_id, $old_status, $new_status, $order) {
    // Bloques preparados para futuras funcionalidades
    if ('pending payment' === $new_status) {
        // TODO: Agregar lógica para pending payment si es necesario
    }
    if ('processing' === $new_status) {
        // TODO: Agregar lógica para processing si es necesario
    }
    if ('completed' === $new_status) {
        // TODO: Agregar lógica para completed si es necesario
    }
    
    // Solo actuar si el nuevo estado es "cancelled"
    if ('cancelled' === $new_status) {
        $tiene_eventos = false;
        foreach ($order->get_items() as $item) {
            $product_id = $item->get_product_id();
            if (get_post_meta($product_id, '_eventmagic_event', true)) {
                $tiene_eventos = true;
                
                // Ajustar stock según tipo de gestión (WooCommerce o FooEvents)
                ajustar_stock_v1($product_id);
                break;
            }
        }
        
        if ($tiene_eventos) {
            // ===== VALIDACIONES MÚLTIPLES =====
            
            // 1. Verificar transaction_id (lo más confiable según IT de Pagopar)
            $transaction_id = $order->get_transaction_id();
            
            // 2. Verificar si el estado anterior era de orden pagada
            $fue_pagada = in_array($old_status, array('processing', 'completed', 'on-hold'));
            
            // 3. Verificar meta específica de Pagopar (si existe)
            $pagopar_confirmado = $order->get_meta('_pagopar_payment_confirmed');
            
            // ===== LOGGING DETALLADO =====
            error_log("=== ANÁLISIS CANCELACIÓN PEDIDO #{$order_id} ===");
            error_log("Estado anterior: {$old_status}");
            error_log("Método de pago: " . $order->get_payment_method());
            error_log("Transaction ID: " . ($transaction_id ? $transaction_id : 'VACÍO'));
            error_log("¿Fue pagada antes?: " . ($fue_pagada ? 'SÍ' : 'NO'));
            error_log("Pagopar confirmado: " . ($pagopar_confirmado ? 'SÍ' : 'NO'));
            
            // ===== DECISIÓN =====
            // Solo cambiar a Refunded si HAY confirmación de pago
            if (empty($transaction_id) && !$fue_pagada && !$pagopar_confirmado) {
                error_log("DECISIÓN: Permanece CANCELADO (sin confirmación de pago)");
                $order->add_order_note(
                    'Orden cancelada sin confirmación de pago de la pasarela. Permanece en estado Cancelado.',
                    false,
                    true
                );
                return; // Salir sin cambiar estado
            }
            
            // Si llegamos aquí, hubo pago confirmado
            error_log("DECISIÓN: Cambiar a REFUNDED (pago confirmado)");
            $order->update_status('refunded', 'Cambio automático: orden con pago confirmado devuelta.');
        }
    }
    
    if ('refunded' === $new_status) {
        // TODO: Agregar lógica para refunded si es necesario
    }
}

/**
 * Ajusta el stock según el tipo de gestión del producto
 * - Si usa WooCommerce stock management: ajusta basado en tickets + constante
 * - Si usa FooEvents: solo audita y guarda metas personalizadas
 * 
 * NOTA: Actualmente deshabilitada temporalmente para desarrollo/testing
 * Para habilitar, comentar las dos primeras líneas dentro de la función
 */
function ajustar_stock_v1($product_id) {
    // TEMPORAL: Deshabilitado para observar comportamiento en producción
    error_log("Stock gestionado por FooEvents para producto #{$product_id}");
    return; // ← Comentar esta línea para habilitar la función completa
    
    // CASO 1: Productos gestionados por WooCommerce (productos simples/temporales)
    if ('yes' === get_post_meta($product_id, '_manage_stock', true)) {
        
        // Contar tickets de FooEvents asociados
        $entradas_vendidas = count(get_posts([
            'post_type'   => 'event_magic_tickets',
            'post_status' => 'publish',
            'meta_key'    => 'fooevents_product_id',
            'meta_value'  => $product_id,
            'fields'      => 'ids',
            'numberposts' => -1
        ]));
        
        if ($entradas_vendidas > 0) {
            // Producto WooCommerce que tiene tickets de FooEvents (caso mixto)
            $stock_correcto = max(ENTRADAS_MAXIMAS_POR_EVENTO - $entradas_vendidas, 0);
            $stock_actual = (int) get_post_meta($product_id, '_stock', true);
            
            if ($stock_actual !== $stock_correcto) {
                update_post_meta($product_id, '_stock', $stock_correcto);
                error_log("Stock WC corregido para producto #{$product_id}: {$stock_actual} → {$stock_correcto} (tickets: {$entradas_vendidas})");
            }
        } else {
            // Producto simple sin tickets - WooCommerce gestiona todo normalmente
            error_log("Producto #{$product_id}: Stock gestionado por WooCommerce (sin tickets FooEvents)");
        }
        
        return;
    }
    
    // CASO 2: Productos gestionados por FooEvents (la mayoría)
    if (get_post_meta($product_id, '_eventmagic_event', true)) {
        
        // Contar tickets REALES generados (la fuente de verdad)
        $entradas_vendidas = count(get_posts([
            'post_type'   => 'event_magic_tickets',
            'post_status' => 'publish',
            'meta_key'    => 'fooevents_product_id',
            'meta_value'  => $product_id,
            'fields'      => 'ids',
            'numberposts' => -1
        ]));
        
        // Calcular capacidad total desde FooEvents
        $capacidad_total = calcular_capacidad_total_fooevents($product_id);
        
        if ($capacidad_total !== false) {
            $stock_disponible = max($capacidad_total - $entradas_vendidas, 0);
            
            // Guardar en metas personalizadas para referencia (NO tocar _stock)
            update_post_meta($product_id, '_fooevents_tickets_vendidos', $entradas_vendidas);
            update_post_meta($product_id, '_fooevents_capacidad_total', $capacidad_total);
            update_post_meta($product_id, '_fooevents_disponible', $stock_disponible);
            update_post_meta($product_id, '_fooevents_ultima_sync', current_time('mysql'));
            
            error_log("FooEvents #{$product_id}: {$entradas_vendidas}/{$capacidad_total} vendidos, {$stock_disponible} disponibles");
        } else {
            error_log("FooEvents #{$product_id}: Stock gestionado por FooEvents (capacidad no determinada)");
        }
        
        return;
    }
    
    // CASO 3: Producto sin gestión de stock
    error_log("Producto #{$product_id}: Sin gestión de stock activa");
}

/**
 * Calcula la capacidad total basándose en la configuración de FooEvents
 */
function calcular_capacidad_total_fooevents($product_id) {
    // Método 1: Meta directa de capacidad
    $capacidad_meta = get_post_meta($product_id, 'WooCommerceEventsCapacity', true);
    if (!empty($capacidad_meta) && is_numeric($capacidad_meta)) {
        return intval($capacidad_meta);
    }
    
    // Método 2: Número de eventos × capacidad por evento
    $num_eventos = get_post_meta($product_id, 'WooCommerceEventsNumEvents', true);
    $capacidad_por_evento = get_post_meta($product_id, 'WooCommerceEventsCapacityPerEvent', true);
    
    if (!empty($num_eventos) && !empty($capacidad_por_evento) && is_numeric($num_eventos) && is_numeric($capacidad_por_evento)) {
        return intval($num_eventos) * intval($capacidad_por_evento);
    }
    
    // Método 3: Configuración serializada/JSON
    $posibles_metas = array(
        'fooevents_bookings_options_serialized',
        '_fooevents_bookings_options',
        'WooCommerceEventsEvent'
    );
    
    foreach ($posibles_metas as $meta_key) {
        $config = get_post_meta($product_id, $meta_key, true);
        
        if (empty($config)) {
            continue;
        }
        
        // Deserializar si es necesario
        if (is_string($config)) {
            $config = maybe_unserialize($config);
        }
        
        // Si tiene múltiples eventos con capacidad individual
        if (isset($config['events']) && is_array($config['events'])) {
            $total = 0;
            foreach ($config['events'] as $evento) {
                if (isset($evento['capacity'])) {
                    $total += intval($evento['capacity']);
                } elseif (isset($evento['stock'])) {
                    $total += intval($evento['stock']);
                }
            }
            if ($total > 0) {
                return $total;
            }
        }
        
        // Capacidad única
        if (isset($config['capacity']) && is_numeric($config['capacity'])) {
            return intval($config['capacity']);
        }
    }
    
    // Método 4: Productos variables - sumar capacidad de variaciones
    $product = wc_get_product($product_id);
    if ($product && $product->is_type('variable')) {
        $variaciones = $product->get_available_variations();
        $total_capacidad = 0;
        
        foreach ($variaciones as $variacion) {
            $var_capacity = get_post_meta($variacion['variation_id'], 'WooCommerceEventsCapacity', true);
            if (!empty($var_capacity) && is_numeric($var_capacity)) {
                $total_capacidad += intval($var_capacity);
            }
        }
        
        if ($total_capacidad > 0) {
            return $total_capacidad;
        }
    }
    
    // No se pudo determinar capacidad
    error_log("⚠️ No se pudo determinar capacidad para producto #{$product_id}");
    return false;
}

// ===== FUNCIÓN DE DEBUG (activa durante desarrollo) =====
add_action('woocommerce_order_status_cancelled', 'debug_fooevents_estructura', 10, 1);

function debug_fooevents_estructura($order_id) {
    $order = wc_get_order($order_id);
    
    foreach ($order->get_items() as $item) {
        $product_id = $item->get_product_id();
        
        if (!get_post_meta($product_id, '_eventmagic_event', true)) {
            continue;
        }
        
        error_log("=== DEBUG PRODUCTO #{$product_id} ===");
        
        // Listar TODOS los meta_keys relacionados con eventos
        $all_meta = get_post_meta($product_id);
        foreach ($all_meta as $key => $value) {
            if (strpos(strtolower($key), 'event') !== false || strpos($key, 'fooevents') !== false) {
                $value_display = is_array($value[0]) ? print_r($value[0], true) : $value[0];
                error_log("  Meta: {$key} = {$value_display}");
            }
        }
        
        // Verificar gestión de stock
        error_log("  _manage_stock: " . get_post_meta($product_id, '_manage_stock', true));
        error_log("  _stock: " . get_post_meta($product_id, '_stock', true));
    }
}

// ===== CRON PARA CANCELAR ÓRDENES PENDIENTES =====

// Define intervalo de 15 minutos
add_filter('cron_schedules', 'cron_intervalo_quince_minutos');
function cron_intervalo_quince_minutos($schedules) {
    $schedules['quince_minutos'] = array(
        'interval' => 900, // 15 minutos = 900 segundos
        'display'  => __('Cada 15 minutos'),
    );
    return $schedules;
}

// Programa el evento si no está programado
add_action('wp', 'programar_cancelacion_pedidos');
function programar_cancelacion_pedidos() {
    if (!wp_next_scheduled('cancelar_pedidos_pendientes')) {
        wp_schedule_event(time(), 'quince_minutos', 'cancelar_pedidos_pendientes');
    }
}

// Función principal del cron
add_action('cancelar_pedidos_pendientes', 'cancelar_pedidos_pendientes_por_tiempo');
function cancelar_pedidos_pendientes_por_tiempo() {
    // Umbral de 30 minutos
    $umbral_tiempo = 30 * MINUTE_IN_SECONDS;

    // Obtener pedidos pendientes antiguos
    $pedidos = wc_get_orders(array(
        'status'     => 'pending',
        'limit'      => -1,
        'orderby'    => 'date',
        'order'      => 'ASC',
        'date_query' => array(
            array(
                'before'    => date('Y-m-d H:i:s', strtotime('-' . $umbral_tiempo . ' seconds')),
                'inclusive' => true,
            ),
        ),
    ));

    // Cancelar pedidos que superan el tiempo
    foreach ($pedidos as $pedido) {
        if ($pedido->get_status() === 'pending') {
            error_log("Cancelando pedido #{$pedido->get_id()} por exceder 30 minutos sin pago.");
            $pedido->update_status('cancelled', 'Pedido cancelado automáticamente por timeout (30 min).');
        }
    }
}