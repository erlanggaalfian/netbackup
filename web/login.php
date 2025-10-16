<?php
require_once __DIR__ . '/../config/db.php';

// Cek jika tidak ada admin, redirect ke setup.php
$stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'");
if ($stmt->fetchColumn() == 0) {
    header('Location: setup.php');
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
            $stmt = $pdo->prepare('SELECT * FROM users WHERE username = ?');
            $stmt->execute([$username]);
            $user = $stmt->fetch();
            if ($user && password_verify($password, $user['password_hash'])) {
                $_SESSION['user'] = [ 'id' => $user['id'], 'username' => $user['username'], 'role' => $user['role'] ];
                app_log('login_success', 'User logged in: ' . $user['username'], $user['id']);
                header('Location: /dashboard');
                exit;
            } else {
                $error = 'Invalid credentials';
                app_log('login_failed', 'Failed login for user: ' . $username);
            }
        } else {
            $error = 'Please enter username and password';
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>NetBackup - Login</title>
  <link rel="icon" type="image/png" href="/img/logo.png">
  <link rel="shortcut icon" type="image/png" href="/img/logo.png">
  <link rel="stylesheet" href="/style/global.css">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
</head>
<body class="auth-page">

<!-- Canvas untuk latar belakang interaktif -->
<canvas id="auth-interactive-bg"></canvas>

<div class="login-container">
<div class="logo-section">
    <img src="/img/logo.png" alt="NetBackup Logo" style="height: 85px; margin-bottom: 16px;">
    <h1 class="logo-title">NetBackup</h1>
    <p class="logo-subtitle">ALWAYS READY TO RISE</p>
</div>

  <?php if ($error): ?>
    <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
    
    <div class="form-group">
      <label class="form-label">
        <svg class="form-icon" fill="currentColor" viewBox="0 0 20 20">
          <path fill-rule="evenodd" d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z" clip-rule="evenodd"></path>
        </svg>
        Username
      </label>
      <input type="text" name="username" class="form-input" placeholder="Masukkan username Anda" required>
    </div>

    <div class="form-group">
      <label class="form-label">
        <svg class="form-icon" fill="currentColor" viewBox="0 0 20 20">
          <path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd"></path>
        </svg>
        Password
      </label>
      <div class="password-container">
        <input type="password" name="password" id="password" class="form-input" placeholder="Masukkan password Anda" required>
        <button type="button" class="password-toggle" onclick="togglePassword()">
          <svg width="16" height="16" fill="currentColor" viewBox="0 0 20 20">
            <path d="M10 12a2 2 0 100-4 2 2 0 000 4z"></path>
            <path fill-rule="evenodd" d="M.458 10C1.732 5.943 5.522 3 10 3s8.268 2.943 9.542 7c-1.274 4.057-5.064 7-9.542 7S1.732 14.057.458 10zM14 10a4 4 0 11-8 0 4 4 0 018 0z" clip-rule="evenodd"></path>
          </svg>
        </button>
      </div>
    </div>

    <div class="form-options">
      <div class="remember-me">
        <input type="checkbox" id="remember" name="remember">
        <label for="remember">Ingat saya</label>
      </div>
      <a href="#" class="forgot-password">Lupa password?</a>
    </div>

    <button type="submit" class="btn login-btn">
      <span>Masuk</span>
      <svg width="16" height="16" fill="currentColor" viewBox="0 0 20 20">
        <path fill-rule="evenodd" d="M10.293 3.293a1 1 0 011.414 0l6 6a1 1 0 010 1.414l-6 6a1 1 0 01-1.414-1.414L14.586 11H3a1 1 0 110-2h11.586l-4.293-4.293a1 1 0 010-1.414z" clip-rule="evenodd"></path>
      </svg>
    </button>
  </form>

    <p class="footer-text">
        &copy; <?php echo date('Y'); ?> <strong>NetBackup</strong>. by Erlangga Alfian.
    </p>
</div>

<script>
function togglePassword() {
  const passwordInput = document.getElementById('password');
  const toggleBtn = document.querySelector('.password-toggle');
  
  if (passwordInput.type === 'password') {
    passwordInput.type = 'text';
    toggleBtn.innerHTML = `
      <svg width="16" height="16" fill="currentColor" viewBox="0 0 20 20">
        <path fill-rule="evenodd" d="M3.707 2.293a1 1 0 00-1.414 1.414l14 14a1 1 0 001.414-1.414l-1.473-1.473A10.014 10.014 0 0019.542 10C18.268 5.943 14.478 3 10 3a9.958 9.958 0 00-4.512 1.074l-1.78-1.781zm4.261 4.26l1.514 1.515a2.003 2.003 0 012.45 2.45l1.514 1.514a4 4 0 00-5.478-5.478z" clip-rule="evenodd"></path>
        <path d="M12.454 16.697L9.75 13.992a4 4 0 01-3.742-3.741L2.335 6.578A9.98 9.98 0 00.458 10c1.274 4.057 5.065 7 9.542 7 .847 0 1.669-.105 2.454-.303z"></path>
      </svg>
    `;
  } else {
    passwordInput.type = 'password';
    toggleBtn.innerHTML = `
      <svg width="16" height="16" fill="currentColor" viewBox="0 0 20 20">
        <path d="M10 12a2 2 0 100-4 2 2 0 000 4z"></path>
        <path fill-rule="evenodd" d="M.458 10C1.732 5.943 5.522 3 10 3s8.268 2.943 9.542 7c-1.274 4.057-5.064 7-9.542 7S1.732 14.057.458 10zM14 10a4 4 0 11-8 0 4 4 0 018 0z" clip-rule="evenodd"></path>
      </svg>
    `;
  }
}

// --- SCRIPT UNTUK LATAR BELAKANG INTERAKTIF ---
document.addEventListener('DOMContentLoaded', function() {
    const canvas = document.getElementById('auth-interactive-bg');
    if (!canvas) return;
    const ctx = canvas.getContext('2d');
    const container = document.querySelector('.login-container');
    let particlesArray;

    function getRgbFromCss(variableName) {
        const colorStr = getComputedStyle(document.documentElement).getPropertyValue(variableName).trim();
        if (colorStr.startsWith('#')) {
            const r = parseInt(colorStr.slice(1, 3), 16);
            const g = parseInt(colorStr.slice(3, 5), 16);
            const b = parseInt(colorStr.slice(5, 7), 16);
            return { r, g, b };
        }
        return { r: 244, g: 247, b: 249 }; // Fallback to bg-body color
    }
    const particleRgb = getRgbFromCss('--color-bg-body');

    function setCanvasSize() {
        canvas.width = window.innerWidth;
        canvas.height = window.innerHeight;
    }
    setCanvasSize();

    class Particle {
        constructor(x, y, dirX, dirY, size, color) { this.x = x; this.y = y; this.directionX = dirX; this.directionY = dirY; this.size = size; this.color = color; }
        draw() { ctx.beginPath(); ctx.arc(this.x, this.y, this.size, 0, Math.PI * 2, false); ctx.fillStyle = this.color; ctx.fill(); }
        update() { if (this.x > canvas.width || this.x < 0) this.directionX = -this.directionX; if (this.y > canvas.height || this.y < 0) this.directionY = -this.directionY; this.x += this.directionX; this.y += this.directionY; this.draw(); }
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
            let color = `rgba(${particleRgb.r}, ${particleRgb.g}, ${particleRgb.b}, 0.5)`;
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
                    ctx.strokeStyle = `rgba(${particleRgb.r}, ${particleRgb.g}, ${particleRgb.b}, ${opacity * 0.3})`;
                    ctx.lineWidth = 1;
                    ctx.beginPath();
                    ctx.moveTo(particlesArray[a].x, particlesArray[a].y);
                    ctx.lineTo(particlesArray[b].x, particlesArray[b].y);
                    ctx.stroke();
                }
            }
        }
    }
    function animate() { requestAnimationFrame(animate); ctx.clearRect(0, 0, innerWidth, innerHeight); for (let i = 0; i < particlesArray.length; i++) particlesArray[i].update(); connect(); }
    
    window.addEventListener('resize', () => { setCanvasSize(); init(); });
    
    // Efek 3D Tilt
    if(container) {
        container.addEventListener('mousemove', (e) => {
            let rect = container.getBoundingClientRect();
            let x = e.clientX - rect.left - rect.width / 2;
            let y = e.clientY - rect.top - rect.height / 2;
            let rotateX = -y / (rect.height / 2) * 5;
            let rotateY = x / (rect.width / 2) * 5;
            container.style.transform = `perspective(1000px) rotateX(${rotateX}deg) rotateY(${rotateY}deg) scale3d(1, 1, 1)`;
        });
        container.addEventListener('mouseleave', () => { container.style.transform = 'perspective(1000px) rotateX(0) rotateY(0) scale3d(1, 1, 1)'; });
    }

    init();
    animate();
});
</script>
</body>
</html>
