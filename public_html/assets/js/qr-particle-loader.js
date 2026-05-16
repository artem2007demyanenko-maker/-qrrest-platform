(function () {
    'use strict';

    var SESSION_KEY = 'qrrest-home-preloader-shown';
    var TOTAL_DURATION = 3200;
    var REDUCED_MOTION_DURATION = 1600;
    var LOGO_PATH = '/assets/img/logo-qrrest.png';
    var FAILSAFE_DURATION = 3500;
    var DESKTOP_FPS = 60;
    var MOBILE_FPS = 40;

    function ready(fn) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', fn, { once: true });
            return;
        }
        fn();
    }

    function clamp(value, min, max) {
        return Math.min(max, Math.max(min, value));
    }

    function easeOutCubic(t) {
        return 1 - Math.pow(1 - t, 3);
    }

    function easeInOutCubic(t) {
        return t < 0.5
            ? 4 * t * t * t
            : 1 - Math.pow(-2 * t + 2, 3) / 2;
    }

    function showCookieBanner() {
        var cookieRoot = document.getElementById('qr-cookie-consent-root');
        var cookie = document.getElementById('qr-cookie-banner') || document.getElementById('cookie-banner');
        if (cookieRoot) {
            cookieRoot.style.opacity = '1';
            cookieRoot.style.pointerEvents = 'none';
            cookieRoot.classList.add('is-visible');
        }
        if (cookie) {
            cookie.style.opacity = '1';
            cookie.style.pointerEvents = 'auto';
            cookie.classList.add('is-visible');
        }
    }

    function finishOverlay(overlay, state) {
        if (!overlay || (state && state.finished)) {
            return;
        }
        if (state) {
            state.finished = true;
            if (state.rafId) {
                cancelAnimationFrame(state.rafId);
            }
            if (state.failsafeTimer) {
                clearTimeout(state.failsafeTimer);
            }
        }

        overlay.classList.add('is-hidden');
        document.body.classList.remove('qr-preloader-lock');
        document.body.classList.remove('preloader-active');
        document.body.classList.add('app-loaded');
        window.dispatchEvent(new CustomEvent('qr:preloader-complete'));

        window.setTimeout(function () {
            if (overlay && overlay.parentNode) {
                overlay.parentNode.removeChild(overlay);
            }
            showCookieBanner();
        }, 620);
    }

    function runReducedMotion(overlay, state) {
        var fallback = overlay.querySelector('[data-preloader-fallback]');
        if (!fallback) {
            finishOverlay(overlay, state);
            return;
        }

        fallback.classList.add('is-visible');

        window.setTimeout(function () {
            finishOverlay(overlay, state);
        }, REDUCED_MOTION_DURATION);
    }

    function buildParticles(image, canvas, overlay) {
        var ctx = canvas.getContext('2d', { alpha: true, desynchronized: true });
        if (!ctx) {
            runReducedMotion(overlay);
            return;
        }

        canvas.style.willChange = 'transform';

        var reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        if (reducedMotion) {
            runReducedMotion(overlay);
            return;
        }

        var isMobile = window.matchMedia('(max-width: 767px)').matches;
        var frameInterval = 1000 / (isMobile ? MOBILE_FPS : DESKTOP_FPS);
        var lastFrame = 0;
        var dpr = Math.min(window.devicePixelRatio || 1, 2);
        var rect = canvas.getBoundingClientRect();
        var width = Math.max(1, Math.floor(rect.width));
        var height = Math.max(1, Math.floor(rect.height));

        canvas.width = Math.floor(width * dpr);
        canvas.height = Math.floor(height * dpr);
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);

        var logoWidth = Math.min(width * 0.74, isMobile ? 310 : 560);
        var aspect = image.naturalWidth / image.naturalHeight || 3.2;
        var logoHeight = logoWidth / aspect;
        var centerX = width / 2;
        var centerY = height / 2;
        var logoX = centerX - (logoWidth / 2);
        var logoY = centerY - (logoHeight / 2);

        var sampleCanvas = document.createElement('canvas');
        var sampleCtx = sampleCanvas.getContext('2d', { willReadFrequently: true });
        if (!sampleCtx) {
            runReducedMotion(overlay);
            return;
        }

        sampleCanvas.width = Math.max(1, Math.round(logoWidth));
        sampleCanvas.height = Math.max(1, Math.round(logoHeight));
        sampleCtx.drawImage(image, 0, 0, sampleCanvas.width, sampleCanvas.height);

        var pixels = sampleCtx.getImageData(0, 0, sampleCanvas.width, sampleCanvas.height).data;
        var gap = isMobile ? 6 : 4;
        var particles = [];

        for (var y = 0; y < sampleCanvas.height; y += gap) {
            for (var x = 0; x < sampleCanvas.width; x += gap) {
                var index = (y * sampleCanvas.width + x) * 4;
                var alpha = pixels[index + 3];
                if (alpha < 96) {
                    continue;
                }
                if (isMobile && Math.random() > 0.4) {
                    continue;
                }

                var r = pixels[index];
                var g = pixels[index + 1];
                var b = pixels[index + 2];

                var sizeRoll = Math.random();
                var particleSize = sizeRoll < 0.45 ? 1 : (sizeRoll < 0.82 ? 2 : 2.5);
                particles.push({
                    startX: Math.random() * width,
                    startY: Math.random() * height,
                    driftX: (Math.random() - 0.5) * 42,
                    driftY: (Math.random() - 0.5) * 42,
                    targetX: logoX + x,
                    targetY: logoY + y,
                    size: isMobile ? Math.max(0.9, particleSize - 0.3) : particleSize,
                    color: 'rgba(' + r + ', ' + g + ', ' + b + ', 0.96)',
                    hueGlow: 'rgba(52, 211, 153, 0.18)',
                    seed: Math.random() * Math.PI * 2
                });
            }
        }

        if (!particles.length) {
            runReducedMotion(overlay);
            return;
        }

        var start = performance.now();
        var state = {
            finished: false,
            rafId: 0,
            failsafeTimer: window.setTimeout(function () {
                finishOverlay(overlay, state);
            }, FAILSAFE_DURATION)
        };

        function frame(now) {
            if (state.finished) {
                return;
            }

            state.rafId = requestAnimationFrame(frame);
            if (lastFrame && now - lastFrame < frameInterval) {
                return;
            }
            lastFrame = now;

            var elapsed = now - start;
            var progress = clamp(elapsed / TOTAL_DURATION, 0, 1);
            var gatherProgress = clamp((elapsed - 500) / 1900, 0, 1);
            var gatherEase = easeOutCubic(gatherProgress);
            var holdProgress = clamp((elapsed - 2400) / 600, 0, 1);
            var overlayFade = elapsed >= 3000 ? easeOutCubic(clamp((elapsed - 3000) / 300, 0, 1)) : 0;
            var peakGlow = clamp(1 - Math.abs(elapsed - 2550) / 150, 0, 1);

            ctx.fillStyle = isMobile ? 'rgba(5, 11, 22, 0.12)' : 'rgba(5, 11, 22, 0.20)';
            ctx.fillRect(0, 0, width, height);

            var bgGlow = ctx.createRadialGradient(width / 2, height / 2, Math.min(width, height) * 0.08, width / 2, height / 2, Math.max(width, height) * 0.56);
            bgGlow.addColorStop(0, 'rgba(16, 185, 129, 0.07)');
            bgGlow.addColorStop(0.55, 'rgba(34, 211, 238, 0.03)');
            bgGlow.addColorStop(1, 'rgba(2, 6, 23, 0)');
            ctx.fillStyle = bgGlow;
            ctx.fillRect(0, 0, width, height);

            particles.forEach(function (particle, i) {
                var jitterStrength = 1 - gatherEase;
                var pulse = Math.sin((elapsed / 180) + particle.seed + i * 0.003);
                var jitterX = Math.cos((elapsed / 240) + particle.seed) * particle.driftX * 0.12 * jitterStrength;
                var jitterY = pulse * particle.driftY * 0.12 * jitterStrength;

                var x = particle.startX + (particle.targetX - particle.startX) * gatherEase + jitterX;
                var y = particle.startY + (particle.targetY - particle.startY) * gatherEase + jitterY;
                var dx = particle.targetX - x;
                var dy = particle.targetY - y;
                var distance = Math.sqrt(dx * dx + dy * dy);
                var approach = clamp(1 - (distance / Math.max(width, height)) * 3.2, 0, 1);
                x += dx * 0.08 * approach;
                y += dy * 0.08 * approach;

                var radiusBoost = holdProgress * 0.45;
                var particleSize = particle.size + radiusBoost;

                ctx.beginPath();
                ctx.fillStyle = particle.color;
                ctx.shadowColor = '#00e0c6';
                ctx.shadowBlur = (isMobile ? 4 : 8) + (gatherProgress * (isMobile ? 2 : 7)) + (peakGlow * (isMobile ? 2 : 5));
                ctx.arc(x, y, particleSize, 0, Math.PI * 2);
                ctx.fill();
            });

            ctx.shadowBlur = 0;

            if (gatherProgress > 0.85) {
                var imageOpacity = clamp((gatherProgress - 0.85) / 0.15, 0, 1) * (0.32 + holdProgress * 0.5);
                var breathe = 1 + (Math.sin((elapsed - 2400) / 240) * 0.008 * holdProgress);
                ctx.save();
                ctx.globalAlpha = imageOpacity * (0.985 + 0.03 * holdProgress);
                ctx.shadowColor = '#00e0c6';
                ctx.shadowBlur = (isMobile ? 6 : 12) + (holdProgress * (isMobile ? 4 : 10)) + (peakGlow * (isMobile ? 4 : 12));
                var drawW = logoWidth * breathe;
                var drawH = logoHeight * breathe;
                var drawX = centerX - drawW / 2;
                var drawY = centerY - drawH / 2;
                ctx.drawImage(image, drawX, drawY, drawW, drawH);
                ctx.restore();
            }

            if (elapsed >= TOTAL_DURATION) {
                finishOverlay(overlay, state);
            }
        }

        state.rafId = requestAnimationFrame(frame);
    }

    ready(function () {
        var overlay = document.querySelector('[data-qr-preloader]');
        if (!overlay) {
            document.body.classList.add('app-loaded');
            return;
        }

        if (sessionStorage.getItem(SESSION_KEY) === '1') {
            overlay.parentNode.removeChild(overlay);
            document.body.classList.remove('preloader-active');
            document.body.classList.add('app-loaded');
            window.dispatchEvent(new CustomEvent('qr:preloader-complete'));
            showCookieBanner();
            return;
        }

        sessionStorage.setItem(SESSION_KEY, '1');
        document.body.classList.add('qr-preloader-lock');

        var canvas = overlay.querySelector('[data-preloader-canvas]');
        if (!canvas) {
            finishOverlay(overlay);
            return;
        }

        var image = new Image();
        image.onload = function () {
            buildParticles(image, canvas, overlay);
        };
        image.onerror = function () {
            runReducedMotion(overlay);
        };
        image.src = LOGO_PATH;
    });
})();
