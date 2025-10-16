</div> <!-- penutup .container -->

<footer class="footer">
    <div class="footer-content">
        <p class="footer-text">
            &copy; <?php echo date('Y'); ?> <strong>NetBackup</strong>. Built to rise - by Erlangga Alfian.
            </p>
        </div>
    </footer>

<!-- Script untuk latar belakang interaktif ditambahkan di sini -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    const canvas = document.getElementById('dashboard-interactive-bg');
    if (!canvas) return;
    
    const ctx = canvas.getContext('2d');
    let particlesArray;

    function getRgbFromCss(variableName) {
        const colorStr = getComputedStyle(document.documentElement).getPropertyValue(variableName).trim();
        if (colorStr.startsWith('#')) {
            const r = parseInt(colorStr.slice(1, 3), 16);
            const g = parseInt(colorStr.slice(3, 5), 16);
            const b = parseInt(colorStr.slice(5, 7), 16);
            return { r, g, b };
        }
        return { r: 48, g: 129, b: 209 }; 
    }

    const particleRgb = getRgbFromCss('--color-primary');

    // **PERBAIKAN KUNCI 1:** Ukuran kanvas selalu sama dengan ukuran viewport
    function setCanvasSize() {
        canvas.width = window.innerWidth;
        canvas.height = window.innerHeight;
    }
    setCanvasSize();

    class Particle {
        constructor(x, y, dirX, dirY, size, color) {
            this.x = x; this.y = y; this.directionX = dirX; this.directionY = dirY; this.size = size; this.color = color;
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
            this.x += this.directionX;
            this.y += this.directionY;
            this.draw();
        }
    }

    function init() {
        particlesArray = [];
        // **PERBAIKAN KUNCI 2:** Jumlah partikel selalu dihitung dari ukuran viewport
        const densityFactor = 9000; 
        let numParticles = (canvas.height * canvas.width) / densityFactor;

        for (let i = 0; i < numParticles; i++) {
            let size = (Math.random() * 2) + 1;
            let x = (Math.random() * ((canvas.width - size * 2) - (size * 2)) + size * 2);
            let y = (Math.random() * ((canvas.height - size * 2) - (size * 2)) + size * 2);
            let dirX = (Math.random() * 0.8) - 0.4;
            let dirY = (Math.random() * 0.8) - 0.4;
            let color = `rgba(${particleRgb.r}, ${particleRgb.g}, ${particleRgb.b}, 0.5)`;
            particlesArray.push(new Particle(x, y, dirX, dirY, size, color));
        }
    }

    function connect() {
        let opacity = 1;
        const connectDistance = (canvas.width / 7) * (canvas.width / 7);

        for (let a = 0; a < particlesArray.length; a++) {
            for (let b = a; b < particlesArray.length; b++) {
                let dist = ((particlesArray[a].x - particlesArray[b].x) ** 2) + ((particlesArray[a].y - particlesArray[b].y) ** 2);
                
                if (dist < connectDistance) {
                    opacity = 1 - (dist / 20000);
                    ctx.strokeStyle = `rgba(${particleRgb.r}, ${particleRgb.g}, ${particleRgb.b}, ${opacity * 0.2})`;
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
    
    // Perbarui kanvas hanya saat ukuran jendela browser berubah
    window.addEventListener('resize', () => {
        setCanvasSize();
        init();
    });

    init();
    animate();
});
</script>

</body>
</html>

