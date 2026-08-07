<?php

/**
 * Primary sidebar — default “stall graffiti” when empty.
 *
 * @package ZeroShits
 */

declare(strict_types=1);

$hasWidgets = function_exists('ap_is_active_sidebar') && ap_is_active_sidebar('sidebar-1');
?>
<aside class="widget-area widget-area--sidebar" id="secondary" aria-label="Sidebar">
<?php
if ($hasWidgets) {
    if (function_exists('ap_dynamic_sidebar')) {
        ap_dynamic_sidebar('sidebar-1');
    } elseif (class_exists('AP_Widgets', false)) {
        AP_Widgets::dynamicSidebar('sidebar-1');
    }
} else {
    ?>
    <section class="widget widget--stall">
        <h2 class="widget-title">Stall rules</h2>
        <ol class="zs-stall-rules">
            <li>If it’s yellow, let it mellow… wait, no, flush it. We’re not monsters.</li>
            <li>One does not simply leave the seat up.</li>
            <li>Winter is coming — bring your own TP.</li>
            <li>May the fart be with you (downwind).</li>
            <li>That’s what she said. Always.</li>
        </ol>
    </section>
    <section class="widget widget--ban">
        <h2 class="widget-title">Zero shits policy</h2>
        <p class="zs-ban-hero">
            <span class="zs-poop-ban zs-poop-ban--xl" aria-hidden="true"><span class="zs-poop-ban__emoji">💩</span></span>
        </p>
        <p>We give <strong>exactly zero</strong>. If you were looking for deep takes, try a museum. If you were looking for bathroom jokes… pull up a throne.</p>
    </section>
    <section class="widget">
        <h2 class="widget-title">Cultural effluent</h2>
        <ul>
            <li>Mr. Hankey’s distant cousin (we don’t claim him)</li>
            <li>Shrek’s swamp HOA notes</li>
            <li>Idiocracy’s president of bathrooms</li>
            <li>South Park season whatever energy</li>
            <li>Beavis &amp; Butt-Head approved (uh-huh-huh)</li>
            <li>Monty Python’s flying toilet seat</li>
        </ul>
    </section>
    <?php
}
?>
</aside>
