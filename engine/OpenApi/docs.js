// Starts Swagger UI on the page OpenApiDocs renders.
//
// A file rather than an inline <script>, so the page works under the default
// Content-Security-Policy (script-src 'self'). The description's URL comes
// from the page's data-spec attribute, which the server escaped; nothing here
// is configuration.
(function () {
    'use strict';

    var root = document.getElementById('swagger-ui');

    if (!root || typeof window.SwaggerUIBundle !== 'function') {
        return;
    }

    window.ui = window.SwaggerUIBundle({
        url: root.getAttribute('data-spec'),
        dom_id: '#swagger-ui',
        deepLinking: true,
        // The badge would send the description to validator.swagger.io.
        validatorUrl: null,
        // A token typed into "Authorize" stays in this tab, not localStorage.
        persistAuthorization: false,
    });
}());
