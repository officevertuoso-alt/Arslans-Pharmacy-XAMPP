<?php
/**
 * === FILE: pharmacy_software/includes/dashboard_card.php ===
 *
 * Reusable Dashboard Stat Card Component — Phase 7
 *
 * Usage:
 *   renderDashboardCard([
 *     'label'       => 'Today\'s Sales',       // Required: card title
 *     'value'       => 'Rs 12,450.00',          // Required: main value string
 *     'icon'        => 'fas fa-rupee-sign',      // Required: FontAwesome icon class
 *     'accent'      => 'success',                // Required: success|warning|danger|info|purple|orange|teal|dark
 *     'icon_bg'     => '#28a745',                // Optional: icon circle bg color (overrides accent default)
 *     'value_color' => '#20c997',                // Optional: override value text color
 *     'trend'       => '▲ 12% vs yesterday',    // Optional: trend line text (HTML allowed)
 *     'trend_color' => 'text-success',           // Optional: Bootstrap text class for trend
 *     'href'        => BASE_URL . 'sales/list.php', // Optional: makes card clickable
 *     'id'          => 'sc-today-sale',          // Optional: id on the .stat-value span for AJAX
 *     'extra_class' => 'afu',                    // Optional: extra classes on wrapper div
 *     'label_color' => '',                       // Optional: override label color
 *   ]);
 */

/**
 * Map accent name → CSS variable / hex for icon background
 */
function dashCardAccentColor(string $accent): string {
    $map = [
        'success' => 'var(--pharmacy-accent, #28a745)',
        'warning' => '#ffc107',
        'danger'  => '#dc3545',
        'info'    => '#17a2b8',
        'purple'  => '#6f42c1',
        'orange'  => '#fd7e14',
        'teal'    => '#20c997',
        'dark'    => 'var(--pharmacy-accent, #343a40)',
        'primary' => 'var(--pharmacy-primary, #3b82f6)',
    ];
    return $map[$accent] ?? 'var(--pharmacy-primary, #3b82f6)';
}

/**
 * Renders a standardised dashboard stat card.
 *
 * @param array $opts  Card options (see file header for keys).
 * @return void        Outputs HTML directly.
 */
function renderDashboardCard(array $opts): void {
    // ── Required ──────────────────────────────────────────────────────────────
    $label  = htmlspecialchars($opts['label']  ?? 'Stat');
    $value  = $opts['value']  ?? '—';           // Already formatted, may contain HTML
    $icon   = $opts['icon']   ?? 'fas fa-chart-bar';
    $accent = $opts['accent'] ?? 'primary';

    // ── Optional ──────────────────────────────────────────────────────────────
    $iconBg      = $opts['icon_bg']     ?? dashCardAccentColor($accent);
    $valueColor  = $opts['value_color'] ?? '';   // empty = inherit from .stat-value
    $trend       = $opts['trend']       ?? '';   // may contain HTML
    $trendColor  = $opts['trend_color'] ?? 'text-muted';
    $href        = $opts['href']        ?? '';
    $valueId     = $opts['id']          ?? '';
    $extraClass  = $opts['extra_class'] ?? '';
    $labelColor  = $opts['label_color'] ?? '';

    // ── Build wrapper attributes ───────────────────────────────────────────
    $wrapClass = 'stat-card stat-card--' . htmlspecialchars($accent);
    if ($extraClass) $wrapClass .= ' ' . htmlspecialchars($extraClass);

    $wrapAttrs = '';
    if ($href) {
        $wrapAttrs .= ' style="cursor:pointer;"';
        $wrapAttrs .= ' onclick="location.href=\'' . htmlspecialchars($href, ENT_QUOTES) . '\'"';
        $wrapAttrs .= ' role="button" tabindex="0"';
        $wrapAttrs .= ' onkeydown="if(event.key===\'Enter\'||event.key===\' \')location.href=\'' . htmlspecialchars($href, ENT_QUOTES) . '\'"';
    }

    // ── Value span ────────────────────────────────────────────────────────
    $valueStyle  = $valueColor ? ' style="color:' . htmlspecialchars($valueColor) . ';"' : '';
    $valueIdAttr = $valueId    ? ' id="' . htmlspecialchars($valueId) . '"'             : '';

    // ── Render ────────────────────────────────────────────────────────────
    ?>
    <div class="<?php echo $wrapClass; ?>"<?php echo $wrapAttrs; ?>>
        <div class="d-flex justify-content-between align-items-start">
            <div class="stat-card__body">
                <div class="stat-label"<?php echo $labelColor ? ' style="color:' . htmlspecialchars($labelColor) . ';"' : ''; ?>>
                    <?php echo $label; ?>
                </div>
                <div class="stat-value"<?php echo $valueStyle . $valueIdAttr; ?>>
                    <?php echo $value; ?>
                </div>
            </div>
            <div class="stat-icon-circle" style="background:<?php echo $iconBg; ?>;">
                <i class="<?php echo htmlspecialchars($icon); ?>"></i>
            </div>
        </div>
        <?php if ($trend): ?>
        <div class="stat-trend <?php echo htmlspecialchars($trendColor); ?>">
            <?php echo $trend; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php
}