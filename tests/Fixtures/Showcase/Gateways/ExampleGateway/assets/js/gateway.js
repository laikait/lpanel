// A gateway's script: /assets/gateway/Example/js/gateway.js
//
// Same directory name as the plugin, different namespace. Module ids are
// qualified by kind for exactly this reason, and so are asset URLs.
export const audit = () => console.debug('audit gateway loaded');
