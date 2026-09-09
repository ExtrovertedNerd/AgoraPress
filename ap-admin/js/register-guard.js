/**
 * AgoraPress first-party registration guard (progressive enhancement).
 *
 * When Web Crypto is available, checking “I am a person” solves a tiny
 * SHA-256 proof-of-work and fills captcha_answer as pow:<n>. Without JS
 * or crypto.subtle, the visible fallback code field stays required.
 */
(function () {
    'use strict';

    function byId(id) {
        return document.getElementById(id);
    }

    function toHex(buffer) {
        var bytes = new Uint8Array(buffer);
        var out = '';
        var i;
        var h;
        for (i = 0; i < bytes.length; i++) {
            h = bytes[i].toString(16);
            out += h.length === 1 ? '0' + h : h;
        }
        return out;
    }

    function sha256hex(str) {
        return crypto.subtle.digest('SHA-256', new TextEncoder().encode(str)).then(toHex);
    }

    function solvePow(token, difficulty) {
        var prefix = '';
        var i;
        for (i = 0; i < difficulty; i++) {
            prefix += '0';
        }
        var n = 0;
        var batchSize = 24;

        function batch() {
            var jobs = [];
            var k;
            if (n > 500000) {
                return Promise.reject(new Error('pow-timeout'));
            }
            for (k = 0; k < batchSize; k++) {
                (function (proof) {
                    jobs.push(sha256hex(token + ':' + proof).then(function (hex) {
                        return hex.slice(0, difficulty) === prefix ? proof : null;
                    }));
                })(String(n + k));
            }
            n += batchSize;
            return Promise.all(jobs).then(function (results) {
                var j;
                for (j = 0; j < results.length; j++) {
                    if (results[j]) {
                        return results[j];
                    }
                }
                return batch();
            });
        }

        return batch();
    }

    function ready(fn) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', fn);
        } else {
            fn();
        }
    }

    ready(function () {
        var root = byId('ap-register-guard');
        if (!root) {
            return;
        }
        if (!window.crypto || !window.crypto.subtle || typeof window.TextEncoder !== 'function') {
            return;
        }

        var ack = byId('ap_guard_ack');
        var answer = byId('reg_captcha_answer');
        var fallback = byId('ap-guard-fallback');
        var tokenInput = root.querySelector('input[name="captcha_token"]');
        var status = byId('ap-guard-status');
        var form = root.closest('form');
        if (!ack || !answer || !tokenInput) {
            return;
        }

        var difficulty = parseInt(root.getAttribute('data-ap-difficulty') || '3', 10);
        if (!(difficulty >= 1 && difficulty <= 6)) {
            difficulty = 3;
        }

        root.classList.add('ap-guard--js');
        if (fallback) {
            fallback.hidden = true;
        }
        answer.removeAttribute('required');
        answer.setAttribute('tabindex', '-1');
        ack.setAttribute('required', 'required');

        var solving = false;
        var solvedFor = '';

        function setStatus(text, isError) {
            if (!status) {
                return;
            }
            if (!text) {
                status.hidden = true;
                status.textContent = '';
                status.classList.remove('ap-guard-status--error');
                return;
            }
            status.hidden = false;
            status.textContent = text;
            status.classList.toggle('ap-guard-status--error', !!isError);
        }

        function clearProof() {
            solvedFor = '';
            answer.value = '';
        }

        function runPow() {
            var token = (tokenInput.value || '').trim();
            if (!token || solving) {
                return Promise.resolve(false);
            }
            if (solvedFor === token && answer.value.indexOf('pow:') === 0) {
                return Promise.resolve(true);
            }
            solving = true;
            root.setAttribute('aria-busy', 'true');
            setStatus('Checking…', false);
            return solvePow(token, difficulty).then(function (proof) {
                answer.value = 'pow:' + proof;
                solvedFor = token;
                setStatus('Ready', false);
                return true;
            }).catch(function () {
                clearProof();
                root.classList.remove('ap-guard--js');
                if (fallback) {
                    fallback.hidden = false;
                }
                answer.setAttribute('required', 'required');
                answer.removeAttribute('tabindex');
                setStatus('Could not complete the check. Type the code below.', true);
                return false;
            }).finally(function () {
                solving = false;
                root.removeAttribute('aria-busy');
            });
        }

        ack.addEventListener('change', function () {
            if (!ack.checked) {
                clearProof();
                setStatus('', false);
                return;
            }
            runPow();
        });

        if (form) {
            form.addEventListener('submit', function (event) {
                if (!ack.checked) {
                    return;
                }
                var token = (tokenInput.value || '').trim();
                if (solvedFor === token && answer.value.indexOf('pow:') === 0) {
                    return;
                }
                event.preventDefault();
                runPow().then(function (ok) {
                    if (!ok) {
                        return;
                    }
                    if (typeof form.requestSubmit === 'function') {
                        form.requestSubmit();
                    } else {
                        HTMLFormElement.prototype.submit.call(form);
                    }
                });
            });
        }
    });
})();
