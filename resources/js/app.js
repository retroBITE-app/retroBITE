import docsRail from './docs-rail';
import globalSearch from './global-search';
import romUpload from './rom-upload';
import transfer from './transfer';
import usbTransfers from './usb-transfers';
// Live updates over Reverb; sets window.Echo and window.live. See echo.js.
import './echo';

// Registered here rather than inline: the CSP allows no inline <script>, and
// the uploader is too much logic to live in an x-data attribute.
document.addEventListener('alpine:init', () => {
    window.Alpine.data('romUpload', romUpload);
    // The documents list beside an open document; see docs-rail.js.
    window.Alpine.data('docsRail', docsRail);
    // The Ctrl+K box's keyboard; see global-search.js.
    window.Alpine.data('globalSearch', globalSearch);
    // Copying a game onto a drive; see transfer.js.
    window.Alpine.data('transfer', transfer);
    // The copying itself, for the whole tab: it outlives the modal and the page.
    window.Alpine.store('usb', usbTransfers());
});
