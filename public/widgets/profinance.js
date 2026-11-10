/* The informers' own start-up, as ProFinance.ru gives it (the domain: the site that shows them). */
(function () {
    var domain = location.hostname || 'informers.profinance.ru';
    var forex = function () { PFForex.widget('forex-tradeflow', '#333333', '#ffffff', domain, true); };
    var cbrf = function () { PFCbrf.widget('cbrf-tradeflow', '#333333', '#ffffff', domain, 'flag', 'on'); };
    if (typeof PFForex !== 'undefined') { forex(); } else { (window.PFForexInit = window.PFForexInit || []).push(forex); }
    if (typeof PFCbrf !== 'undefined') { cbrf(); } else { (window.PFCbrfInit = window.PFCbrfInit || []).push(cbrf); }
})();
