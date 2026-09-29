/**
 * Latar belakang 3D khusus halaman auth (login, logout, setup).
 * Terpisah dari interactive-bg.js yang dipakai halaman dashboard.
 */
(function () {
    if (window.__nbAuthBgStarted) return;
    window.__nbAuthBgStarted = true;

    function initTilt(container) {
        if (!container) return;
        container.addEventListener('mousemove', (e) => {
            const rect = container.getBoundingClientRect();
            const x = e.clientX - rect.left - rect.width / 2;
            const y = e.clientY - rect.top - rect.height / 2;
            const rotateX = (-y / (rect.height / 2)) * 2;
            const rotateY = (x / (rect.width / 2)) * 2;
            container.style.transform = `perspective(1000px) rotateX(${rotateX}deg) rotateY(${rotateY}deg) scale3d(1, 1, 1)`;
        });
        container.addEventListener('mouseleave', () => {
            container.style.transform = 'perspective(1000px) rotateX(0) rotateY(0) scale3d(1, 1, 1)';
        });
    }

    function startBackground() {
        const canvas = document.getElementById('auth-bg');
        if (!canvas) return;

        const ctx = canvas.getContext('2d');
        let particlesArray;
        let mouseX = null;
        let mouseY = null;

        const particleRgb = { r: 255, g: 255, b: 255 };
        const lineOpacity = 0.35;

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
                    if (dist < 120) {
                        this.x -= dx / dist * 0.6;
                        this.y -= dy / dist * 0.6;
                    }
                }

                this.x += this.directionX;
                this.y += this.directionY;
                this.draw();
            }
        }

        function init() {
            particlesArray = [];
            const numParticles = (canvas.height * canvas.width) / 7000;
            for (let i = 0; i < numParticles; i++) {
                const size = Math.random() * 2.5 + 1;
                const x = Math.random() * (canvas.width - size * 4) + size * 2;
                const y = Math.random() * (canvas.height - size * 4) + size * 2;
                const dirX = Math.random() * 0.6 - 0.3;
                const dirY = Math.random() * 0.6 - 0.3;
                const alpha = Math.random() * 0.3 + 0.2;
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

        document.addEventListener('mousemove', (e) => {
            mouseX = e.clientX;
            mouseY = e.clientY;
        });
        document.addEventListener('mouseleave', () => {
            mouseX = null;
            mouseY = null;
        });

        window.addEventListener('resize', () => {
            setCanvasSize();
            init();
        });

        init();
        animate();
        initTilt(document.querySelector('.login-container'));
    }

    function scheduleStart() {
        if ('requestIdleCallback' in window) {
            requestIdleCallback(startBackground, { timeout: 1500 });
        } else {
            setTimeout(startBackground, 200);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', scheduleStart);
    } else {
        scheduleStart();
    }
})();
