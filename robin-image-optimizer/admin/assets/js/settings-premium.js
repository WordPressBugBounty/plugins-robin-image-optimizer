jQuery(document).ready(function($) {
    // Disable premium dropdown buttons for non-licensed users
    $('button[data-value="googlepage"]').attr('disabled', 'disabled');
    $('button[data-value="background"]').attr('disabled', 'disabled');
});
