import romUpload from './rom-upload';
import transfer from './transfer';
// Live updates over Reverb; sets window.Echo and window.live. See echo.js.
import './echo';

// Registered here rather than inline: the CSP allows no inline <script>, and
// the uploader is too much logic to live in an x-data attribute.
document.addEventListener('alpine:init', () => {
    window.Alpine.data('romUpload', romUpload);
    // Copying a game onto a drive; see transfer.js.
    window.Alpine.data('transfer', transfer);
});
