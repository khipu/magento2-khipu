// kws.js declara `class Khipu` en el scope global sin asignarla a window, así que
// shim.exports no la encuentra; init la lee directamente. Así se evita un script
// inline, que la CSP del checkout bloquearía.
var config = {
    paths: {
        'khipu-kws': 'https://js.khipu.com/v1/kws'
    },
    shim: {
        'khipu-kws': {
            init: function () {
                return typeof Khipu !== 'undefined' ? Khipu : undefined;
            }
        }
    }
};
