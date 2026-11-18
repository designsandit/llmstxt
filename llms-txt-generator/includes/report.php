<?php
/**
 * Kit de visibilidad en IA: lo que se envía junto al llms.txt.
 *
 *  1. Datos estructurados (schema JSON-LD LocalBusiness/Organization con sameAs).
 *  2. Revisión del robots.txt para los bots de búsqueda y de entrenamiento de IA.
 *  3. Coherencia del NAP: si el nombre, la dirección y el teléfono aparecen en las páginas del sitio.
 *
 * Cada revisión es independiente. Si una falla, el email se envía igual, sin esa sección.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'LLMSTXT_MAX_PROFILES',  10 );
define( 'LLMSTXT_NAP_MAX_PAGES', 8 );
define( 'LLMSTXT_FETCH_UA',      'Mozilla/5.0 (compatible; LLMsTxtGenerator/' . LLMSTXT_VERSION . '; +https://www.designsandit.com/)' );

// ═════════════════════════════════════════════════════════════════════════════
// Utilidades
// ═════════════════════════════════════════════════════════════════════════════

/** El NAP se guarda como "nombre\ndirección\nteléfono" (v3.5+). Las versiones antiguas guardaban texto libre. */
function llmstxt_parse_nap( $nap ) {
    $lines = array_values( array_filter( array_map( 'trim', preg_split( '/\R/', (string) $nap ) ) ) );
    $out   = [ 'biz' => $lines[0] ?? '', 'address' => '', 'phone' => '' ];
    foreach ( array_slice( $lines, 1 ) as $line ) {
        $digits = preg_replace( '/\D/', '', $line );
        if ( ! $out['phone'] && strlen( $digits ) >= 7 && strlen( $digits ) >= 0.6 * strlen( preg_replace( '/\s/', '', $line ) ) ) {
            $out['phone'] = $line;
        } elseif ( ! $out['address'] ) {
            $out['address'] = $line;
        }
    }
    return $out;
}

/** Normaliza una lista de URLs de perfiles (una por línea). @return string[] */
function llmstxt_parse_profiles( $text ) {
    $out = [];
    foreach ( preg_split( '/[\s,]+/', (string) $text ) as $raw ) {
        $raw = trim( $raw );
        if ( '' === $raw ) continue;
        if ( ! preg_match( '#^https?://#i', $raw ) ) $raw = 'https://' . $raw;
        if ( ! llmstxt_is_http_url( $raw ) || false === strpos( (string) wp_parse_url( $raw, PHP_URL_HOST ), '.' ) ) continue;
        if ( ! in_array( $raw, $out, true ) ) $out[] = $raw;
        if ( count( $out ) >= LLMSTXT_MAX_PROFILES ) break;
    }
    return $out;
}

/** Texto comparable: sin etiquetas, sin tildes, en minúsculas y con espacios simples. */
function llmstxt_norm_text( $s ) {
    $s = html_entity_decode( (string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
    $s = function_exists( 'remove_accents' ) ? remove_accents( $s ) : $s;
    $s = function_exists( 'mb_strtolower' ) ? mb_strtolower( $s, 'UTF-8' ) : strtolower( $s );
    return trim( preg_replace( '/\s+/u', ' ', $s ) );
}

function llmstxt_path_label( $url ) {
    $p = (string) wp_parse_url( $url, PHP_URL_PATH );
    return ( '' === $p || '/' === $p ) ? 'página principal' : $p;
}

// ═════════════════════════════════════════════════════════════════════════════
// 2. robots.txt
// ═════════════════════════════════════════════════════════════════════════════

/** Bots revisados: token => [nombre visible, tipo]. "search" = deciden si te citan; "training" = entrenamiento. */
function llmstxt_report_bots() {
    return apply_filters( 'llmstxt_report_bots', [
        'OAI-SearchBot'     => [ 'ChatGPT (búsqueda)', 'search' ],
        'ChatGPT-User'      => [ 'ChatGPT (cuando un usuario pide abrir tu web)', 'search' ],
        'Claude-SearchBot'  => [ 'Claude (búsqueda)', 'search' ],
        'Claude-User'       => [ 'Claude (cuando un usuario pide abrir tu web)', 'search' ],
        'PerplexityBot'     => [ 'Perplexity', 'search' ],
        'Perplexity-User'   => [ 'Perplexity (cuando un usuario pide abrir tu web)', 'search' ],
        'Bingbot'           => [ 'Bing (alimenta Copilot y la búsqueda de ChatGPT)', 'search' ],
        'Googlebot'         => [ 'Google (buscador y AI Overviews)', 'search' ],
        'GPTBot'            => [ 'OpenAI (entrenamiento de modelos)', 'training' ],
        'ClaudeBot'         => [ 'Anthropic (entrenamiento de modelos)', 'training' ],
        'Google-Extended'   => [ 'Google Gemini (entrenamiento de modelos)', 'training' ],
        'Applebot-Extended' => [ 'Apple Intelligence (entrenamiento de modelos)', 'training' ],
        'CCBot'             => [ 'Common Crawl (datos abiertos usados para entrenar IA)', 'training' ],
    ] );
}

/** Agrupa el robots.txt en bloques [agents => [], rules => [[allow, path]]] (RFC 9309). */
function llmstxt_robots_groups( $txt ) {
    $groups = [];
    $cur    = null;
    foreach ( preg_split( '/\R/', (string) $txt ) as $line ) {
        $line = trim( preg_replace( '/#.*/', '', $line ) );
        if ( '' === $line || false === strpos( $line, ':' ) ) continue;
        list( $key, $value ) = array_map( 'trim', explode( ':', $line, 2 ) );
        $key = strtolower( $key );
        if ( 'user-agent' === $key ) {
            if ( null === $cur || ! empty( $cur['rules'] ) ) {
                if ( null !== $cur ) $groups[] = $cur;
                $cur = [ 'agents' => [], 'rules' => [] ];
            }
            $cur['agents'][] = strtolower( $value );
        } elseif ( ( 'allow' === $key || 'disallow' === $key ) && null !== $cur ) {
            $cur['rules'][] = [ 'allow' => 'allow' === $key, 'path' => $value ];
        }
    }
    if ( null !== $cur ) $groups[] = $cur;
    return $groups;
}

function llmstxt_robots_pattern_matches( $pattern, $path ) {
    $anchored = '$' === substr( $pattern, -1 );
    $core     = $anchored ? substr( $pattern, 0, -1 ) : $pattern;
    $regex    = '#^' . str_replace( '\*', '.*', preg_quote( $core, '#' ) ) . ( $anchored ? '$' : '' ) . '#';
    return (bool) preg_match( $regex, $path );
}

/** ¿Puede el bot $token rastrear $path? Regla más larga gana; en empate gana Allow (RFC 9309). */
function llmstxt_robots_allowed( $txt, $token, $path = '/' ) {
    $groups  = llmstxt_robots_groups( $txt );
    $token   = strtolower( $token );
    $matched = array_filter( $groups, function( $g ) use ( $token ) { return in_array( $token, $g['agents'], true ); } );
    if ( ! $matched ) {
        $matched = array_filter( $groups, function( $g ) { return in_array( '*', $g['agents'], true ); } );
    }
    $best = null;
    foreach ( $matched as $g ) {
        foreach ( $g['rules'] as $r ) {
            if ( '' === $r['path'] || ! llmstxt_robots_pattern_matches( $r['path'], $path ) ) continue;
            $len = strlen( $r['path'] );
            if ( null === $best || $len > $best['len'] || ( $len === $best['len'] && $r['allow'] ) ) {
                $best = [ 'len' => $len, 'allow' => $r['allow'] ];
            }
        }
    }
    return null === $best ? true : $best['allow'];
}

/** Descarga y evalúa el robots.txt del sitio. */
function llmstxt_check_robots( $website ) {
    $host   = wp_parse_url( $website, PHP_URL_HOST );
    $scheme = wp_parse_url( $website, PHP_URL_SCHEME ) ?: 'https';
    $url    = $scheme . '://' . $host . '/robots.txt';
    $result = [ 'url' => $url, 'status' => 'ok', 'message' => '', 'bots' => [] ];

    $response = wp_safe_remote_get( $url, [ 'timeout' => 10, 'redirection' => 3, 'user-agent' => LLMSTXT_FETCH_UA, 'limit_response_size' => 512 * KB_IN_BYTES ] );
    $txt      = '';
    if ( is_wp_error( $response ) ) {
        $result['status']  = 'error';
        $result['message'] = 'No pudimos descargar tu robots.txt (' . $response->get_error_message() . ').';
    } else {
        $code = (int) wp_remote_retrieve_response_code( $response );
        $body = (string) wp_remote_retrieve_body( $response );
        if ( 200 === $code && '<' !== substr( ltrim( $body ), 0, 1 ) ) {
            $txt = $body;
        } elseif ( 200 === $code || ( $code >= 400 && $code < 500 ) ) {
            // Sin robots.txt válido: según el estándar, todo está permitido.
            $result['status']  = 'none';
            $result['message'] = 'Tu sitio no tiene robots.txt, así que ningún bot está bloqueado.';
        } else {
            $result['status']  = 'error';
            $result['message'] = "Tu robots.txt respondió HTTP $code. Mientras falle, algunos bots pueden dejar de rastrear tu sitio.";
        }
    }

    if ( 'error' !== $result['status'] ) {
        foreach ( llmstxt_report_bots() as $token => $info ) {
            $result['bots'][ $token ] = [ 'label' => $info[0], 'kind' => $info[1], 'allowed' => llmstxt_robots_allowed( $txt, $token, '/' ) ];
        }
    }
    return $result;
}

// ═════════════════════════════════════════════════════════════════════════════
// 3. Coherencia del NAP
// ═════════════════════════════════════════════════════════════════════════════

/** Descarga una página y devuelve su texto normalizado y los teléfonos de sus enlaces tel:. */
function llmstxt_fetch_page_text( $url ) {
    $response = wp_safe_remote_get( $url, [ 'timeout' => 8, 'redirection' => 3, 'user-agent' => LLMSTXT_FETCH_UA, 'limit_response_size' => 2 * MB_IN_BYTES ] );
    if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) return null;
    $html = (string) wp_remote_retrieve_body( $response );
    preg_match_all( '/href\s*=\s*["\']tel:([^"\']+)/i', $html, $m );
    $tels = array_map( 'rawurldecode', $m[1] );
    $html = preg_replace( '#<(script|style|noscript|svg)\b[^>]*>.*?</\1>#is', ' ', $html );
    $text = llmstxt_norm_text( wp_strip_all_tags( str_replace( '>', '> ', $html ) ) );
    return [ 'text' => $text, 'tels' => $tels ];
}

function llmstxt_phone_key( $s ) {
    $d = preg_replace( '/\D/', '', (string) $s );
    return strlen( $d ) >= 7 ? substr( $d, -7 ) : '';
}

/** Palabras significativas de una dirección (sin "calle", "cra", "no", etc.). */
function llmstxt_address_tokens( $address ) {
    $stop  = [ 'calle', 'carrera', 'cra', 'kra', 'cll', 'avenida', 'av', 'ave', 'diagonal', 'transversal', 'numero', 'num', 'nro', 'no', 'de', 'del', 'la', 'el', 'los', 'las', 'y', 'the', 'and', 'st', 'street', 'rd', 'road', 'suite', 'ste', 'apt', 'piso', 'oficina', 'of', 'local', 'barrio', 'sector', 'colombia', 'usa', 'us' ];
    $parts = preg_split( '/[^a-z0-9]+/', llmstxt_norm_text( $address ) );
    $out   = [];
    foreach ( $parts as $p ) {
        if ( '' === $p || in_array( $p, $stop, true ) ) continue;
        if ( strlen( $p ) < 3 && ! preg_match( '/\d/', $p ) ) continue;
        $out[] = $p;
    }
    return array_values( array_unique( $out ) );
}

/**
 * Busca el nombre, la dirección y el teléfono en las páginas indicadas.
 * Limitación conocida: no ve el texto que una página carga con JavaScript.
 */
function llmstxt_check_nap( array $urls, $biz, $address, $phone ) {
    $items = [
        'name'    => [ 'label' => 'Nombre',    'value' => $biz,     'found' => [] ],
        'address' => [ 'label' => 'Dirección', 'value' => $address, 'found' => [] ],
        'phone'   => [ 'label' => 'Teléfono',  'value' => $phone,   'found' => [], 'others' => [] ],
    ];
    $name_n  = llmstxt_norm_text( $biz );
    $tokens  = llmstxt_address_tokens( $address );
    $p_key   = llmstxt_phone_key( $phone );
    $checked = [];
    $failed  = [];

    foreach ( array_slice( $urls, 0, LLMSTXT_NAP_MAX_PAGES ) as $url ) {
        $page = llmstxt_fetch_page_text( $url );
        if ( null === $page ) { $failed[] = llmstxt_path_label( $url ); continue; }
        $label     = llmstxt_path_label( $url );
        $checked[] = $label;

        if ( '' !== $name_n && false !== strpos( $page['text'], $name_n ) ) $items['name']['found'][] = $label;

        if ( $tokens ) {
            $hits = 0;
            foreach ( $tokens as $t ) {
                if ( preg_match( '/(^|[^a-z0-9])' . preg_quote( $t, '/' ) . '($|[^a-z0-9])/', $page['text'] ) ) $hits++;
            }
            if ( $hits / count( $tokens ) >= 0.6 ) $items['address']['found'][] = $label;
        }

        // Teléfonos: los de enlaces tel: y los que parecen teléfono en el texto.
        preg_match_all( '/\+?\d[\d\s().\-]{5,}\d/', $page['text'], $pm );
        $candidates = array_merge( $page['tels'], $pm[0] );
        $match      = false;
        foreach ( $candidates as $c ) {
            $digits = preg_replace( '/\D/', '', $c );
            if ( strlen( $digits ) < 7 || strlen( $digits ) > 15 ) continue;
            if ( $p_key && llmstxt_phone_key( $c ) === $p_key ) {
                $match = true;
            } elseif ( in_array( $c, $page['tels'], true ) ) {
                // Solo se reportan como "otros" los de enlaces tel:, que seguro son teléfonos.
                $items['phone']['others'][ llmstxt_phone_key( $c ) ] = trim( $c );
            }
        }
        if ( $match ) $items['phone']['found'][] = $label;
    }
    $items['phone']['others'] = array_values( $items['phone']['others'] );

    return [ 'checked' => $checked, 'failed' => $failed, 'items' => $items ];
}

// ═════════════════════════════════════════════════════════════════════════════
// 1. Datos estructurados (schema.org)
// ═════════════════════════════════════════════════════════════════════════════

function llmstxt_schema_types() {
    return [ 'LocalBusiness', 'Organization', 'ProfessionalService', 'HomeAndConstructionBusiness', 'GeneralContractor', 'Electrician', 'Plumber', 'RoofingContractor', 'HVACBusiness', 'HousePainter', 'Locksmith', 'MovingCompany', 'Dentist', 'MedicalClinic', 'Physician', 'Optician', 'Pharmacy', 'VeterinaryCare', 'LegalService', 'Attorney', 'Notary', 'AccountingService', 'FinancialService', 'InsuranceAgency', 'RealEstateAgent', 'TravelAgency', 'Restaurant', 'CafeOrCoffeeShop', 'Bakery', 'BarOrPub', 'FoodEstablishment', 'BeautySalon', 'HairSalon', 'DaySpa', 'NailSalon', 'HealthAndBeautyBusiness', 'ExerciseGym', 'SportsActivityLocation', 'AutoRepair', 'AutoDealer', 'AutomotiveBusiness', 'Store', 'ClothingStore', 'ElectronicsStore', 'FurnitureStore', 'HardwareStore', 'Florist', 'LodgingBusiness', 'Hotel', 'EducationalOrganization', 'ChildCare', 'EmploymentAgency', 'EntertainmentBusiness' ];
}

/**
 * Pide a OpenAI (sin búsqueda web, salida JSON validada) el tipo de negocio, una descripción y
 * la dirección separada en partes, a partir del llms.txt ya generado. @return array|WP_Error
 */
function llmstxt_schema_ai( $biz, $address, $website, $llms_content, $city = '' ) {
    $api_key = get_option( 'llmstxt_openai_key' );
    if ( ! $api_key ) return new WP_Error( 'no_key', 'Sin API key.' );

    $model   = llmstxt_get_model();
    $prompt  = "Business name: {$biz}\nWebsite: {$website}\nAddress as written by the owner: {$address}\n"
             . ( '' !== $city ? "City (given by the owner, use it as address_locality): {$city}\n" : '' ) . "\n"
             . "llms.txt generated from the business website:\n---\n" . mb_substr( (string) $llms_content, 0, 6000 ) . "\n---\n\n"
             . "Return data for a schema.org JSON-LD block:\n"
             . "- schema_type: the most specific type from the list that fits the business.\n"
             . "- description: one factual sentence (max 200 characters) in the language of the business: what it does, where. No superlatives.\n"
             . "- Split the address into its parts. Use \"\" for any part that is not clearly present. address_country is the ISO 3166-1 alpha-2 code or \"\".";
    $props   = [];
    foreach ( [ 'description', 'street_address', 'address_locality', 'address_region', 'postal_code', 'address_country' ] as $k ) {
        $props[ $k ] = [ 'type' => 'string' ];
    }
    $payload = [
        'model' => $model,
        'input' => $prompt,
        'text'  => [ 'format' => [
            'type'   => 'json_schema',
            'name'   => 'business_schema',
            'strict' => true,
            'schema' => [
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => array_merge( [ 'schema_type' ], array_keys( $props ) ),
                'properties'           => array_merge( [ 'schema_type' => [ 'type' => 'string', 'enum' => llmstxt_schema_types() ] ], $props ),
            ],
        ] ],
    ];
    if ( llmstxt_is_reasoning_model( $model ) ) $payload['reasoning'] = [ 'effort' => 'low' ];

    $response = wp_remote_post( 'https://api.openai.com/v1/responses', [
        'timeout' => 90,
        'headers' => [ 'Authorization' => 'Bearer ' . $api_key, 'Content-Type' => 'application/json' ],
        'body'    => wp_json_encode( $payload ),
    ] );
    if ( is_wp_error( $response ) ) return $response;
    if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) return llmstxt_openai_error( $response );

    $data = json_decode( llmstxt_extract_content( json_decode( wp_remote_retrieve_body( $response ), true ) ), true );
    if ( ! is_array( $data ) || empty( $data['schema_type'] ) || ! in_array( $data['schema_type'], llmstxt_schema_types(), true ) ) {
        return new WP_Error( 'schema_parse', 'La respuesta de OpenAI para el schema no tenía el formato esperado.' );
    }
    return $data;
}

/** Construye el schema. Si la IA no responde, usa LocalBusiness con la dirección tal cual. */
function llmstxt_build_schema( $biz, $address, $phone, $website, array $profiles, $llms_content, $city = '' ) {
    $ai     = llmstxt_schema_ai( $biz, $address, $website, $llms_content, $city );
    $use_ai = ! is_wp_error( $ai );
    if ( ! $use_ai ) llmstxt_log( 'Schema sin IA: ' . $ai->get_error_message() );

    $postal = [ '@type' => 'PostalAddress' ];
    $parts  = [ 'streetAddress' => 'street_address', 'addressLocality' => 'address_locality', 'addressRegion' => 'address_region', 'postalCode' => 'postal_code', 'addressCountry' => 'address_country' ];
    if ( $use_ai ) {
        foreach ( $parts as $schema_key => $ai_key ) {
            $v = trim( (string) ( $ai[ $ai_key ] ?? '' ) );
            if ( '' !== $v ) $postal[ $schema_key ] = $v;
        }
    }
    if ( empty( $postal['streetAddress'] ) ) $postal['streetAddress'] = $address;
    if ( empty( $postal['addressLocality'] ) && '' !== $city ) $postal['addressLocality'] = $city;

    $schema = [
        '@context'  => 'https://schema.org',
        '@type'     => $use_ai ? $ai['schema_type'] : 'LocalBusiness',
        'name'      => $biz,
        'url'       => $website,
        'telephone' => $phone,
        'address'   => $postal,
    ];
    if ( $use_ai && '' !== trim( (string) $ai['description'] ) ) $schema['description'] = trim( $ai['description'] );
    if ( $profiles ) $schema['sameAs'] = array_values( $profiles );

    return [ 'data' => $schema, 'ai' => $use_ai ];
}

function llmstxt_schema_snippet( array $schema ) {
    return "<script type=\"application/ld+json\">\n"
        . wp_json_encode( $schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
        . "\n</script>\n";
}

// ═════════════════════════════════════════════════════════════════════════════
// Informe completo
// ═════════════════════════════════════════════════════════════════════════════

/** Ejecuta las tres revisiones de un lead. Nunca lanza: cada parte que falle queda en null. */
function llmstxt_build_report( $lead, $llms_content ) {
    $nap      = llmstxt_parse_nap( $lead->nap );
    $urls     = array_values( array_filter( array_map( 'trim', preg_split( '/\R/', (string) $lead->urls_submitted ) ) ) );
    $profiles = llmstxt_parse_profiles( $lead->profiles ?? '' );
    $report   = [ 'generated' => current_time( 'mysql' ), 'schema' => null, 'robots' => null, 'nap' => null ];
    $city     = trim( (string) ( $lead->city ?? '' ) );
    // Dirección sin la ciudad que se le añadió al guardar: la ciudad va aparte en el schema.
    $street   = ( '' !== $city && substr( $nap['address'], -strlen( ', ' . $city ) ) === ', ' . $city ) ? substr( $nap['address'], 0, -strlen( ', ' . $city ) ) : $nap['address'];

    try {
        $report['schema'] = llmstxt_build_schema( $nap['biz'], $street, $nap['phone'], $lead->website, $profiles, $llms_content, $city );
    } catch ( Throwable $e ) { llmstxt_log( 'Schema: ' . $e->getMessage() ); }
    try {
        $report['robots'] = llmstxt_check_robots( $lead->website );
    } catch ( Throwable $e ) { llmstxt_log( 'robots.txt: ' . $e->getMessage() ); }
    try {
        if ( $urls ) $report['nap'] = llmstxt_check_nap( $urls, $nap['biz'], $street, $nap['phone'] );
    } catch ( Throwable $e ) { llmstxt_log( 'NAP: ' . $e->getMessage() ); }

    return $report;
}

/** Texto del informe para el email (texto plano). */
function llmstxt_report_text( array $report, $website ) {
    $host = wp_parse_url( $website, PHP_URL_HOST );
    $out  = [];

    if ( ! empty( $report['robots'] ) ) {
        $r     = $report['robots'];
        $lines = [ '== Revisión de tu robots.txt ==' ];
        if ( 'error' === $r['status'] ) {
            $lines[] = $r['message'];
        } else {
            if ( $r['message'] ) $lines[] = $r['message'];
            $blocked = [];
            $lines[] = '';
            $lines[] = 'Bots que deciden si la IA te cita:';
            foreach ( $r['bots'] as $token => $b ) {
                if ( 'search' !== $b['kind'] ) continue;
                $lines[] = ( $b['allowed'] ? '  ✓ ' : '  ✗ BLOQUEADO: ' ) . $b['label'] . " ($token)";
                if ( ! $b['allowed'] ) $blocked[] = $token;
            }
            if ( $blocked ) {
                $lines[] = '';
                $lines[] = 'Para que puedan leerte, añade esto a tu robots.txt (' . $r['url'] . '):';
                foreach ( $blocked as $i => $t ) {
                    if ( $i ) $lines[] = '';
                    $lines[] = "  User-agent: $t";
                    $lines[] = '  Allow: /';
                }
            }
            $lines[] = '';
            $lines[] = 'Bots de entrenamiento de modelos (permitirlos o no es una decisión tuya; no afecta a que te citen):';
            foreach ( $r['bots'] as $token => $b ) {
                if ( 'training' !== $b['kind'] ) continue;
                $lines[] = ( $b['allowed'] ? '  · permitido: ' : '  · bloqueado: ' ) . $b['label'] . " ($token)";
            }
        }
        $out[] = implode( "\n", $lines );
    }

    if ( ! empty( $report['nap'] ) ) {
        $n     = $report['nap'];
        $total = count( $n['checked'] );
        $lines = [ '== ¿Tus datos coinciden en tu web? (NAP) ==' ];
        if ( ! $total ) {
            $lines[] = 'No pudimos abrir ninguna de tus páginas para revisarlo.';
        } else {
            $lines[] = "Revisamos $total " . ( 1 === $total ? 'página' : 'páginas' ) . '. La IA confía más en un negocio cuando su nombre, dirección y teléfono son idénticos en todas partes.';
            foreach ( $n['items'] as $key => $it ) {
                if ( '' === trim( (string) $it['value'] ) ) continue;
                if ( $it['found'] ) {
                    $lines[] = '  ✓ ' . $it['label'] . ' ("' . $it['value'] . '"): aparece en ' . implode( ', ', $it['found'] );
                } else {
                    $lines[] = '  ✗ ' . $it['label'] . ' ("' . $it['value'] . '"): no lo encontramos escrito igual en ninguna de las páginas revisadas.';
                }
                if ( 'phone' === $key && ! empty( $it['others'] ) ) {
                    $lines[] = '    Teléfonos distintos que sí aparecen como enlace: ' . implode( ', ', $it['others'] ) . '. Revisa cuál es el correcto y usa uno solo.';
                }
            }
            if ( $n['failed'] ) $lines[] = '  (No pudimos abrir: ' . implode( ', ', $n['failed'] ) . ')';
            $lines[] = '  Nota: si tu web carga los datos con JavaScript, puede que no los veamos aunque estén.';
        }
        $out[] = implode( "\n", $lines );
    }

    if ( ! empty( $report['schema'] ) ) {
        $s     = $report['schema']['data'];
        $lines = [ '== Datos estructurados (adjunto: datos-estructurados.txt) ==' ];
        $lines[] = 'Es una ficha de tu negocio en el formato que leen Google y los buscadores con IA (schema.org, tipo ' . $s['@type'] . ').';
        $lines[] = 'Cómo usarlo: copia el contenido del adjunto y pégalo en el <head> de tu página principal';
        $lines[] = '(en WordPress puedes usar un plugin de "código en cabecera"). Si ya usas Rank Math o Yoast, revisa que no';
        $lines[] = 'publiquen otra ficha del mismo negocio: debe haber una sola.';
        $lines[] = empty( $s['sameAs'] )
            ? 'Consejo: añade los enlaces a tus perfiles (Google Business Profile, Facebook, LinkedIn…) en "sameAs" para que la IA sepa que son tuyos.'
            : ( 1 === count( $s['sameAs'] ) ? 'Incluye tu perfil' : 'Incluye tus ' . count( $s['sameAs'] ) . ' perfiles' ) . ' en "sameAs", para que la IA sepa que son tuyos.';
        $out[] = implode( "\n", $lines );
    }

    return implode( "\n\n", $out );
}
