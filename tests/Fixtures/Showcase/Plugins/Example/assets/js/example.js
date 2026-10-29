// A plugin's script, published from modules/Plugins/Example/assets/.
//
// Apache denies modules/ outright -- it has to, since module.php and every
// repository lives there. This file is reachable only as
// /assets/plugin/Example/js/example.js, which is the whole point of the asset
// manager: a published directory inside a denied one.
export const customers = () => fetch('api/v1/customers').then((r) => r.json());
