<?php
require_once __DIR__ . '/../config/db.php';

// Cek apakah sudah ada admin
$stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'");
if ($stmt->fetchColumn() > 0) {
    header('Location: login.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf(post('csrf'))) {
        $error = 'Invalid CSRF token';
    } else {
        $username = post('username');
        $password = post('password');
        if ($username && $password) {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $stmt = $pdo->prepare('INSERT INTO users(username, password_hash, role) VALUES(?,?,?)');
            try {
                $stmt->execute([$username, $hash, 'admin']);
                header('Location: /login?status=setup_success');
                exit;
            } catch (Throwable $e) {
                $error = 'Username already exists';
            }
        } else {
            $error = 'All fields required';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>NetBackup - Setup Awal</title>
  <link rel="icon" type="image/png" href="/img/logo.png">
  <link rel="shortcut icon" type="image/png" href="/img/logo.png">
  <link rel="stylesheet" href="/style/global.css">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
</head>
<body class="auth-page-interactive">

  <canvas id="interactive-bg"></canvas>

  <div class="login-wrapper">
    <div class="login-container">
      <div class="logo-section">
        <h1 class="logo-title">Setup Awal</h1>
        <p class="logo-subtitle">Buat akun administrator pertama</p>
      </div>

      <?php if ($error): ?>
        <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
      <?php endif; ?>

      <form method="post">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
        <div class="form-group">
          <label class="form-label">Username Admin</label>
          <input type="text" name="username" class="form-input" placeholder="Masukkan username" required>
        </div>
        <div class="form-group">
          <label class="form-label">Password</label>
          <input type="password" name="password" class="form-input" placeholder="Masukkan password" required>
        </div>
        <button type="submit" class="btn login-btn">Buat Akun</button>
      </form>
    </div>
  </div>

<script>
    document.addEventListener('DOMContentLoaded', () => {
        const canvas = document.getElementById('interactive-bg');
        if (!canvas) return;
        const ctx = canvas.getContext('2d');
        let particlesArray;

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
            let numParticles = (canvas.height * canvas.width) / 9000;
            for (let i = 0; i < numParticles; i++) {
                let size = (Math.random() * 2) + 1;
                let x = (Math.random() * ((innerWidth - size * 2) - (size * 2)) + size * 2);
                let y = (Math.random() * ((innerHeight - size * 2) - (size * 2)) + size * 2);
                let dirX = (Math.random() * 0.8) - 0.4;
                let dirY = (Math.random() * 0.8) - 0.4;
                let color = 'rgba(244, 247, 249, 0.5)';
                particlesArray.push(new Particle(x, y, dirX, dirY, size, color));
            }
        }

        function connect() {
            let opacity = 1;
            for (let a = 0; a < particlesArray.length; a++) {
                for (let b = a; b < particlesArray.length; b++) {
                    let dist = ((particlesArray[a].x - particlesArray[b].x) ** 2) + ((particlesArray[a].y - particlesArray[b].y) ** 2);
                    if (dist < (canvas.width / 7) * (canvas.height / 7)) {
                        opacity = 1 - (dist / 20000);
                        ctx.strokeStyle = `rgba(244, 247, 249, ${opacity * 0.2})`;
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
            ctx.clearRect(0, 0, innerWidth, innerHeight);
            for (let i = 0; i < particlesArray.length; i++) particlesArray[i].update();
            connect();
        }

        window.addEventListener('resize', () => { setCanvasSize(); init(); });
        init();
        animate();
    });
</script>
</body>
</html>
