<?php
/**
 * Leads: exportación a CSV, borrado (manual, automático por antigüedad y por lead)
 * e integración con las herramientas de privacidad de WordPress (exportar/borrar datos personales).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'LLMSTXT_PURGE_HOOK', 'llmstxt_purge_old_leads' );

function llmstxt_retention_days() {
    return min( 3650, absint( get_option( 'llmstxt_retention_days', 0 ) ) );
}

/** Borra los leads con más de $days días. @return int|false filas borradas. */
function llmstxt_delete_leads_older_than( $days ) {
    global $wpdb;
    $days = absint( $days );
    if ( $days < 1 ) return 0;
    $cutoff = gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) . ' UTC' ) - $days * DAY_IN_SECONDS );
    return $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . llmstxt_table() . ' WHERE created_at < %s', $cutoff ) );
}

// ─── Borrado automático (diario) ─────────────────────────────────────────────

add_action( 'init', function() {
    if ( ! wp_next_scheduled( LLMSTXT_PURGE_HOOK ) ) {
        wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', LLMSTXT_PURGE_HOOK );
    }
} );

add_action( LLMSTXT_PURGE_HOOK, function() {
    $days = llmstxt_retention_days();
    if ( ! $days ) return;
    $deleted = llmstxt_delete_leads_older_than( $days );
    update_option( 'llmstxt_last_purge', [ 'time' => current_time( 'mysql' ), 'deleted' => (int) $deleted ], false );
    if ( $deleted ) llmstxt_log( "Borrado automático: $deleted leads con más de $days días." );
} );

register_deactivation_hook( LLMSTXT_PLUGIN_FILE, function() {
    wp_clear_scheduled_hook( LLMSTXT_PURGE_HOOK );
} );

// ─── Ajustes ─────────────────────────────────────────────────────────────────

add_action( 'admin_init', function() {
    register_setting( 'llmstxt_options', 'llmstxt_retention_days', [ 'sanitize_callback' => function( $v ) {
        return min( 3650, absint( $v ) );
    } ] );

    if ( function_exists( 'wp_add_privacy_policy_content' ) ) {
        wp_add_privacy_policy_content( 'LLMs.txt Generator', wp_kses_post(
            '<p>Cuando usas el generador de llms.txt guardamos tu nombre, email, sitio web, datos de contacto del negocio (NAP), '
            . 'las URLs que nos indicas y tu dirección IP. Los usamos para generar el archivo, enviártelo por email y contactarte sobre nuestros servicios. '
            . 'Las URLs y el NAP se envían a OpenAI para generar el archivo. '
            . ( llmstxt_retention_days() ? 'Estos datos se borran automáticamente a los ' . llmstxt_retention_days() . ' días.' : 'Puedes pedir que borremos tus datos en cualquier momento.' )
            . '</p>'
        ) );
    }
} );

add_action( 'llmstxt_settings_fields', function() {
    ?>
    <tr><th colspan="2"><h2 style="margin:0">Privacidad de los leads</h2></th></tr>
    <tr>
        <th><label for="llmstxt_retention_days">Borrar leads automáticamente</label></th>
        <td>
            después de <input type="number" min="0" max="3650" id="llmstxt_retention_days" name="llmstxt_retention_days"
                   value="<?php echo esc_attr( llmstxt_retention_days() ); ?>" style="width:90px" /> días
            <p class="description"><code>0</code> = nunca. Se revisa una vez al día. Exporta los leads a CSV antes si quieres conservarlos.</p>
        </td>
    </tr>
    <?php
}, 20 );

add_action( 'llmstxt_diagnostics_rows', function( $ok, $fail, $warn ) {
    $days = llmstxt_retention_days();
    $last = get_option( 'llmstxt_last_purge' );
    echo '<tr><td>Privacidad: retención</td><td>';
    if ( $days ) {
        echo $ok . 'Los leads se borran a los ' . esc_html( $days ) . ' días.';
        if ( $last ) echo ' <small>(última limpieza: ' . esc_html( $last['time'] ) . ', ' . esc_html( $last['deleted'] ) . ' borrados)</small>';
    } else {
        echo $warn . 'Sin borrado automático: los datos personales se guardan indefinidamente.';
    }
    echo '</td></tr>';
}, 20, 3 );

// ─── Página de leads: barra de herramientas y acciones por fila ──────────────

add_action( 'llmstxt_leads_toolbar', function() {
    $days = llmstxt_retention_days() ?: 365;
    ?>
    <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin:12px 0">
        <a class="button button-primary" href="<?php echo esc_url( llmstxt_action_url( 'llmstxt_export_csv' ) ); ?>">Exportar todos a CSV</a>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0"
              onsubmit="return confirm('¿Borrar definitivamente los leads con más de ' + this.days.value + ' días? Esta acción no se puede deshacer.');">
            <input type="hidden" name="action" value="llmstxt_delete_old" />
            <?php wp_nonce_field( 'llmstxt_delete_old' ); ?>
            Borrar leads con más de
            <input type="number" name="days" min="1" max="3650" value="<?php echo esc_attr( $days ); ?>" style="width:80px" /> días
            <button type="submit" class="button">Borrar</button>
        </form>
        <span class="description">Borrado automático: <?php echo llmstxt_retention_days()
            ? esc_html( 'a los ' . llmstxt_retention_days() . ' días' )
            : 'desactivado (<a href="' . esc_url( admin_url( 'options-general.php?page=llmstxt-settings' ) ) . '">configurar</a>)'; ?></span>
    </div>
    <?php
} );

add_action( 'llmstxt_lead_row_actions', function( $lead ) {
    printf(
        ' <a class="button button-small button-link-delete" href="%s" onclick="return confirm(\'%s\');">Borrar</a>',
        esc_url( llmstxt_action_url( 'llmstxt_delete_lead', [ 'lead_id' => $lead->id ] ) ),
        esc_js( '¿Borrar definitivamente el lead de ' . $lead->email . '?' )
    );
} );

add_action( 'admin_post_llmstxt_delete_lead', function() {
    llmstxt_check_admin_action( 'llmstxt_delete_lead' );
    global $wpdb;
    $lead_id = isset( $_GET['lead_id'] ) ? absint( $_GET['lead_id'] ) : 0;
    $deleted = $wpdb->delete( llmstxt_table(), [ 'id' => $lead_id ], [ '%d' ] );
    llmstxt_admin_redirect( 'llmstxt-leads', $deleted ? 'success' : 'error', $deleted ? "Lead #$lead_id borrado." : "No se pudo borrar el lead #$lead_id." );
} );

add_action( 'admin_post_llmstxt_delete_old', function() {
    llmstxt_check_admin_action( 'llmstxt_delete_old' );
    $days = isset( $_POST['days'] ) ? absint( $_POST['days'] ) : 0;
    if ( $days < 1 ) llmstxt_admin_redirect( 'llmstxt-leads', 'error', 'Indica un número de días válido.' );
    $deleted = llmstxt_delete_leads_older_than( $days );
    llmstxt_admin_redirect( 'llmstxt-leads', 'success', sprintf( '%d leads con más de %d días borrados.', (int) $deleted, $days ) );
} );

// ─── Exportación CSV ─────────────────────────────────────────────────────────

/** Evita que Excel/Sheets interpreten un valor como fórmula (inyección CSV). */
function llmstxt_csv_safe( $value ) {
    $value = (string) $value;
    return ( '' !== $value && in_array( $value[0], [ '=', '+', '-', '@', "\t", "\r" ], true ) ) ? "'" . $value : $value;
}

add_action( 'admin_post_llmstxt_export_csv', function() {
    llmstxt_check_admin_action( 'llmstxt_export_csv' );
    global $wpdb;

    $delimiter = apply_filters( 'llmstxt_csv_delimiter', ';' ); // ";" abre bien en Excel en español
    nocache_headers();
    header( 'Content-Type: text/csv; charset=UTF-8' );
    header( 'Content-Disposition: attachment; filename="llmstxt-leads-' . gmdate( 'Y-m-d' ) . '.csv"' );

    $out = fopen( 'php://output', 'w' );
    fwrite( $out, "\xEF\xBB\xBF" ); // BOM: Excel reconoce las tildes
    fputcsv( $out, [ 'ID', 'Fecha', 'Nombre', 'Email', 'Sitio web', 'NAP', 'URLs', 'Perfiles', 'Estado', 'Detalle', 'Intentos', 'IP' ], $delimiter, '"', '' );

    $columns = 'id, created_at, name, email, website, nap, urls_submitted, profiles, status, error_message, attempts, ip_address';
    $last_id = PHP_INT_MAX;
    do {
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT $columns FROM " . llmstxt_table() . ' WHERE id < %d ORDER BY id DESC LIMIT 500',
            $last_id
        ), ARRAY_A );
        foreach ( $rows as $row ) {
            $last_id = (int) $row['id'];
            $row['urls_submitted'] = str_replace( "\n", ' | ', (string) $row['urls_submitted'] );
            $row['profiles']       = str_replace( "\n", ' | ', (string) $row['profiles'] );
            $row['nap']            = str_replace( "\n", ' | ', (string) $row['nap'] );
            fputcsv( $out, array_map( 'llmstxt_csv_safe', array_values( $row ) ), $delimiter, '"', '' );
        }
    } while ( count( $rows ) === 500 );

    fclose( $out );
    exit;
} );

// ─── Herramientas → Exportar / Borrar datos personales (RGPD) ────────────────

add_filter( 'wp_privacy_personal_data_exporters', function( $exporters ) {
    $exporters['llms-txt-generator'] = [
        'exporter_friendly_name' => 'LLMs.txt Generator',
        'callback'               => function( $email ) {
            global $wpdb;
            $rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . llmstxt_table() . ' WHERE email = %s', $email ) );
            $items = [];
            foreach ( $rows as $r ) {
                $items[] = [
                    'group_id'    => 'llmstxt-leads',
                    'group_label' => 'Solicitudes de llms.txt',
                    'item_id'     => 'llmstxt-lead-' . $r->id,
                    'data'        => [
                        [ 'name' => 'Fecha',     'value' => $r->created_at ],
                        [ 'name' => 'Nombre',    'value' => $r->name ],
                        [ 'name' => 'Email',     'value' => $r->email ],
                        [ 'name' => 'Sitio web', 'value' => $r->website ],
                        [ 'name' => 'NAP',       'value' => $r->nap ],
                        [ 'name' => 'URLs',      'value' => $r->urls_submitted ],
                        [ 'name' => 'Perfiles',  'value' => $r->profiles ],
                        [ 'name' => 'IP',        'value' => $r->ip_address ],
                    ],
                ];
            }
            return [ 'data' => $items, 'done' => true ];
        },
    ];
    return $exporters;
} );

add_filter( 'wp_privacy_personal_data_erasers', function( $erasers ) {
    $erasers['llms-txt-generator'] = [
        'eraser_friendly_name' => 'LLMs.txt Generator',
        'callback'             => function( $email ) {
            global $wpdb;
            $deleted = (int) $wpdb->delete( llmstxt_table(), [ 'email' => $email ], [ '%s' ] );
            return [
                'items_removed'  => $deleted > 0,
                'items_retained' => false,
                'messages'       => $deleted ? [ "Se borraron $deleted solicitudes de llms.txt." ] : [],
                'done'           => true,
            ];
        },
    ];
    return $erasers;
} );
