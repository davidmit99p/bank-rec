<?php
// What is deployed, shown quietly in the footer.
//
// Changed by hand whenever something is pushed, so "has my last change landed?"
// can be answered by looking at the page rather than by asking Plesk.
const APP_VERSION = '2026-09-20';

// When this file arrived on the server, which is when the last deployment
// happened - it is rewritten on every push. Read from the file itself rather
// than typed, so it cannot be wrong.
//
// Shown in London time whatever the server is set to.
function deployed_at()
{
    $t = @filemtime(__FILE__);
    if (!$t) return APP_VERSION;
    try {
        $d = new DateTime('@' . $t);
        $d->setTimezone(new DateTimeZone('Europe/London'));
        return $d->format('j M Y, H:i');
    } catch (Throwable $e) {
        return date('j M Y, H:i', $t);
    }
}
