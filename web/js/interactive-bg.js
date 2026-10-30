/**
 * Latar belakang partikel interaktif — dipanggil via includes/interactive-bg.php
 * Partikel bereaksi terhadap posisi kursor di seluruh halaman dashboard.
 */
(function () {
    if (window.__nbInteractiveBgStarted) return;
    window.__nbInteractiveBgStarted = true;

    // Hemat baterai + aksesibilitas: jangan jalankan loop animasi partikel
    // bila user memilih reduced motion, memakai perangkat sentuh, atau layar kecil.
    function shouldAnimate() {
        try {
            if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return false;
            if (window.matchMedia('(pointer: coarse)').matches) return false;
            if (window.innerWidth <= 768) return false;
        } catch (e) { /* matchMedia tidak tersedia -> animasi seperti biasa */ }
        return true;
    }

    const animateEnabled = shouldAnimate();

    function getRgbFromCss(variableName) {
        const colorStr = getComputedStyle(document.documentElement).getPropertyValue(variableName).trim();
        if (colorStr.startsWith('#')) {
            return {
                r: parseInt(colorStr.slice(1, 3), 16),
                g: parseInt(colorStr.slice(3, 5), 16),
                b: parseInt(colorStr.slice(5, 7), 16),
            };
        }
        return { r: 48, g: 129, b: 209 };
    }

    function startBackground() {
        const canvas = document.getElementById('interactive-bg');
        if (!canvas) return;

        const ctx = canvas.getContext('2d');
        let particlesArray;
        let mouseX = null;
        let mouseY = null;

        const isHeavyPage = document.body.classList.contains('page-heavy');
        const particleRgb = getRgbFromCss('--color-primary');
        const lineOpacity = 0.25;
        const densityFactor = isHeavyPage ? 16000 : 8000;
        const mouseRadius = 100;

        function setCanvasSize() {
            canvas.width = window.innerWidth;
            canvas.height = window.innerHeight;
        }
        setCanvasSize();

        class Particle {
            constructor(x, y, dirX, dirY, size, color) {
                this.x = x;
                this.y = y;
                this.directionX = dirX;
                this.directionY = dirY;
                this.size = size;
                this.color = color;
            }
            draw() {
                ctx.beginPath();
                ctx.arc(this.x, this.y, this.size, 0, Math.PI * 2, false);
                ctx.fillStyle = this.color;
                ctx.fill();
            }
            update() {
                if (this.x > canvas.width || this.x < 0) this.directionX = -this.directionX;
                if (this.y > canvas.height || this.y < 0) this.directionY = -this.directionY;

                if (mouseX !== null && mouseY !== null) {
                    const dx = mouseX - this.x;
                    const dy = mouseY - this.y;
                    const dist = Math.sqrt(dx * dx + dy * dy);
                    if (dist < mouseRadius && dist > 0) {
                        const force = (mouseRadius - dist) / mouseRadius * 0.5;
                        this.x -= (dx / dist) * force;
                        this.y -= (dy / dist) * force;
                    }
                }

                this.x += this.directionX;
                this.y += this.directionY;
                this.draw();
            }
        }

        function init() {
            particlesArray = [];
            const numParticles = (canvas.height * canvas.width) / densityFactor;
            for (let i = 0; i < numParticles; i++) {
                const size = Math.random() * 2 + 1;
                const x = Math.random() * (canvas.width - size * 4) + size * 2;
                const y = Math.random() * (canvas.height - size * 4) + size * 2;
                const dirX = Math.random() * 0.6 - 0.3;
                const dirY = Math.random() * 0.6 - 0.3;
                const alpha = Math.random() * 0.2 + 0.3;
                const color = `rgba(${particleRgb.r}, ${particleRgb.g}, ${particleRgb.b}, ${alpha})`;
                particlesArray.push(new Particle(x, y, dirX, dirY, size, color));
            }
        }

        function connect() {
            const connectDistance = (canvas.width / 6) * (canvas.width / 6);
            for (let a = 0; a < particlesArray.length; a++) {
                for (let b = a; b < particlesArray.length; b++) {
                    const dist = ((particlesArray[a].x - particlesArray[b].x) ** 2)
                        + ((particlesArray[a].y - particlesArray[b].y) ** 2);
                    if (dist < connectDistance) {
                        const opacity = 1 - dist / 18000;
                        ctx.strokeStyle = `rgba(${particleRgb.r}, ${particleRgb.g}, ${particleRgb.b}, ${opacity * lineOpacity})`;
                        ctx.lineWidth = 1;
                        ctx.beginPath();
                        ctx.moveTo(particlesArray[a].x, particlesArray[a].y);
                        ctx.lineTo(particlesArray[b].x, particlesArray[b].y);
                        ctx.stroke();
                    }
                }
            }
        }

        function animate() {
            requestAnimationFrame(animate);
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            for (let i = 0; i < particlesArray.length; i++) particlesArray[i].update();
            connect();
        }

        // Satu frame statis untuk mode hemat (tanpa requestAnimationFrame).
        function renderStatic() {
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            for (let i = 0; i < particlesArray.length; i++) particlesArray[i].draw();
            connect();
        }

        if (animateEnabled) {
            document.addEventListener('mousemove', (e) => {
                mouseX = e.clientX;
                mouseY = e.clientY;
            });
            document.addEventListener('mouseleave', () => {
                mouseX = null;
                mouseY = null;
            });
        }

        window.addEventListener('resize', () => {
            setCanvasSize();
            init();
            if (!animateEnabled) renderStatic();
        });

        init();
        if (animateEnabled) {
            animate();
        } else {
            renderStatic();
        }
    }

    function scheduleStart() {
        if ('requestIdleCallback' in window) {
            requestIdleCallback(startBackground, { timeout: 2000 });
        } else {
            setTimeout(startBackground, 300);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', scheduleStart);
    } else {
        scheduleStart();
    }
})();
