/* GML Language Switcher - Frontend JS */
(function () {
    'use strict';

    /* ── 1. Dropdown toggle — teleport panel to <body> ── */
    function initDropdowns() {
        document.querySelectorAll('.gml-style-dropdown .gml-dropdown').forEach(function (dropdown) {
            var btn = dropdown.querySelector('.gml-dropdown-btn');
            var menu = dropdown.querySelector('.gml-dropdown-menu');
            if (!btn || !menu) return;

            // Teleport the dropdown menu to <body> so no theme CSS can reach it
            menu.style.setProperty('display', 'none', 'important');
            document.body.appendChild(menu);
            menu.classList.add('gml-dropdown-teleported');

            // Store reference for positioning
            btn._gmlMenu = menu;
            menu._gmlBtn = btn;
            menu._gmlDropdown = dropdown;

            btn.addEventListener('click', function (e) {
                e.stopPropagation();
                var isOpen = dropdown.classList.contains('open');
                closeAllDropdowns();
                if (!isOpen) {
                    dropdown.classList.add('open');
                    btn.setAttribute('aria-expanded', 'true');
                    // Show first (off-screen) so we can measure, then position
                    menu.style.setProperty('display', 'block', 'important');
                    positionMenu(btn, menu);
                }
            });
        });

        document.addEventListener('click', function (e) {
            // Don't close if clicking inside a teleported menu
            if (e.target.closest('.gml-dropdown-teleported')) return;
            closeAllDropdowns();
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') closeAllDropdowns();
        });

        // Reposition on scroll/resize
        var reposTimer;
        function onRepos() {
            clearTimeout(reposTimer);
            reposTimer = setTimeout(function () {
                document.querySelectorAll('.gml-dropdown.open').forEach(function (d) {
                    var b = d.querySelector('.gml-dropdown-btn');
                    if (b && b._gmlMenu) positionMenu(b, b._gmlMenu);
                });
            }, 16);
        }
        window.addEventListener('scroll', onRepos, true);
        window.addEventListener('resize', onRepos);
    }

    function closeAllDropdowns() {
        document.querySelectorAll('.gml-dropdown.open').forEach(function (d) {
            d.classList.remove('open');
            var b = d.querySelector('.gml-dropdown-btn');
            if (b) {
                b.setAttribute('aria-expanded', 'false');
                if (b._gmlMenu) b._gmlMenu.style.setProperty('display', 'none', 'important');
            }
        });
    }

    function positionMenu(btn, menu) {
        var rect = btn.getBoundingClientRect();
        var scrollX = window.pageXOffset || document.documentElement.scrollLeft;
        var scrollY = window.pageYOffset || document.documentElement.scrollTop;
        var menuWidth = menu.offsetWidth || 160;

        // Position below the button, right-aligned
        var top = rect.bottom + scrollY + 4;
        var left = rect.right + scrollX - menuWidth;

        // Clamp to viewport
        if (left < 8) left = rect.left + scrollX;
        if (left + menuWidth > document.documentElement.clientWidth - 8) {
            left = document.documentElement.clientWidth - menuWidth - 8 + scrollX;
        }

        menu.style.setProperty('position', 'absolute', 'important');
        menu.style.setProperty('top', top + 'px', 'important');
        menu.style.setProperty('left', left + 'px', 'important');
    }

    /* ── 2. Menu style sync ── */
    function initMenuStyleSync() {
        document.querySelectorAll('.gml-language-switcher').forEach(function (switcher) {
            var li = switcher.closest('li');
            if (!li) return;

            var list = li.parentElement;
            if (!list || (list.tagName !== 'UL' && list.tagName !== 'OL')) return;

            var refLink = null;
            var siblings = list.children;
            for (var i = 0; i < siblings.length; i++) {
                var sib = siblings[i];
                if (sib === li || sib.tagName !== 'LI') continue;
                var a = sib.querySelector('a');
                if (a && !a.closest('.sub-menu') && !a.closest('.gml-language-switcher')) {
                    refLink = a;
                    break;
                }
            }
            if (!refLink) return;

            var cs = window.getComputedStyle(refLink);

            // Only sync the trigger button and its label, NOT dropdown items
            var btn = switcher.querySelector('.gml-dropdown-btn');
            var btnLabel = btn ? btn.querySelector('.gml-lang-label') : null;
            var targets = [switcher];
            if (btn) targets.push(btn);
            if (btnLabel) targets.push(btnLabel);

            switcher.querySelectorAll('.gml-lang-button').forEach(function (b) {
                targets.push(b);
                var l = b.querySelector('.gml-lang-label');
                if (l) targets.push(l);
            });

            var props = ['fontFamily', 'fontSize', 'fontWeight', 'color',
                         'lineHeight', 'letterSpacing', 'textTransform'];

            targets.forEach(function (el) {
                props.forEach(function (prop) {
                    el.style.setProperty(
                        prop.replace(/([A-Z])/g, '-$1').toLowerCase(),
                        cs[prop],
                        'important'
                    );
                });
            });

            var refLi = refLink.closest('li');
            if (refLi && refLi !== li) {
                var liCs = window.getComputedStyle(refLi);
                ['padding', 'paddingTop', 'paddingRight', 'paddingBottom', 'paddingLeft',
                 'margin', 'marginTop', 'marginRight', 'marginBottom', 'marginLeft'
                ].forEach(function (p) {
                    li.style[p] = liCs[p];
                });
            }
        });
    }

    /* ── 3. Internal link rewriting ── */
    function initLinkRewriting() {
        if (typeof gmlData === 'undefined') return;

        var currentLang = gmlData.currentLang;
        var sourceLang  = gmlData.sourceLang;
        var languages   = gmlData.languages;
        var homeUrl     = gmlData.homeUrl;

        if (!currentLang || currentLang === sourceLang) return;
        if (languages.indexOf(currentLang) === -1) return;

        var prefix = '/' + currentLang + '/';
        var homeOrigin = homeUrl.replace(/\/$/, '');

        function shouldRewrite(href) {
            if (!href) return false;
            if (/^(mailto:|tel:|javascript:|#)/i.test(href)) return false;
            if (href.charAt(0) === '#') return false;

            var path;
            if (/^https?:\/\//i.test(href)) {
                if (href.indexOf(homeOrigin) !== 0) return false;
                path = href.slice(homeOrigin.length) || '/';
            } else if (href.charAt(0) === '/') {
                path = href;
            } else {
                path = '/' + href;
            }

            if (/^\/(wp-admin|wp-login\.php)/i.test(path)) return false;
            var langPattern = new RegExp('^/(' + languages.join('|') + ')(/|$)');
            if (langPattern.test(path)) return false;

            return true;
        }

        function rewriteHref(href) {
            if (/^https?:\/\//i.test(href)) {
                var path = href.slice(homeOrigin.length) || '/';
                if (path.charAt(0) !== '/') path = '/' + path;
                return homeOrigin + prefix + path.replace(/^\//, '');
            }
            var p = href.charAt(0) === '/' ? href : '/' + href;
            return prefix + p.replace(/^\//, '');
        }

        function rewriteLinks(root) {
            root.querySelectorAll('a[href]').forEach(function (a) {
                if (a.closest('.gml-language-switcher') || a.closest('.gml-dropdown-teleported')) return;
                var href = a.getAttribute('href');
                if (shouldRewrite(href)) {
                    a.setAttribute('href', rewriteHref(href));
                }
            });
            root.querySelectorAll('form[action]').forEach(function (form) {
                var action = form.getAttribute('action');
                if (shouldRewrite(action)) {
                    form.setAttribute('action', rewriteHref(action));
                }
            });
        }

        rewriteLinks(document);

        if (typeof MutationObserver !== 'undefined') {
            var observer = new MutationObserver(function (mutations) {
                mutations.forEach(function (mutation) {
                    mutation.addedNodes.forEach(function (node) {
                        if (node.nodeType === 1) rewriteLinks(node);
                    });
                });
            });
            observer.observe(document.body, { childList: true, subtree: true });
        }
    }

    /* ── Init ── */
    function init() {
        initDropdowns();
        initMenuStyleSync();
        initLinkRewriting();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
