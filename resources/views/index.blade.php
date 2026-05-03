<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>Duck Hunter</title>

        <script>
            (() => {
                const stored = localStorage.getItem('theme');
                const dark = window.matchMedia('(prefers-color-scheme: dark)').matches;
                document.documentElement.dataset.theme = stored ?? (dark ? 'dark' : 'light');
            })();
        </script>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet" />

        <!-- Styles / Scripts -->
        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite(['resources/css/app.css', 'resources/js/app.js'])
        @endif
        
        <style>
            body { margin: 0; overflow: hidden; background-color: #87CEEB; user-select: none; }
            canvas { display: block; cursor: crosshair; }
            #ui { position: absolute; top: 0; left: 0; width: 100%; padding: 20px; pointer-events: none; display: flex; justify-content: space-between; box-sizing: border-box; }
            .stat { font-size: 24px; font-weight: bold; color: white; text-shadow: 2px 2px 4px rgba(0,0,0,0.5); }
            #game-over { display: none; position: absolute; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); color: white; flex-direction: column; align-items: center; justify-content: center; z-index: 10; }
            #game-over h1 { font-size: 48px; margin-bottom: 20px; }
            #start-screen { position: absolute; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); color: white; display: flex; flex-direction: column; align-items: center; justify-content: center; z-index: 20; }
        </style>
    </head>
    <body class="antialiased font-sans">
        
        <div id="ui">
            <div class="stat">Score: <span id="score">0</span></div>
            <div class="stat">Time: <span id="time">60</span>s</div>
        </div>

        <div id="start-screen">
            <h1 class="text-5xl font-bold mb-4">Bird Hunter</h1>
            <p class="mb-8 text-xl">Click to shoot the birds. You have 60 seconds!</p>
            <button id="start-btn" class="btn btn-primary btn-lg">Start Game</button>
        </div>

        <div id="game-over">
            <h1 class="text-5xl font-bold">Game Over!</h1>
            <p class="text-2xl mb-8">Final Score: <span id="final-score">0</span></p>
            <button id="restart-btn" class="btn btn-primary btn-lg">Play Again</button>
        </div>

        <canvas id="gameCanvas"></canvas>

        <script>
            const canvas = document.getElementById('gameCanvas');
            const ctx = canvas.getContext('2d');
            
            let w, h;
            let birds = [];
            let particles = [];
            let score = 0;
            let timeLeft = 60;
            let isPlaying = false;
            let lastTime = 0;
            let spawnTimer = 0;
            let gameInterval = null;

            function resize() {
                w = canvas.width = window.innerWidth;
                h = canvas.height = window.innerHeight;
            }
            window.addEventListener('resize', resize);
            resize();

            class Bird {
                constructor() {
                    this.y = Math.random() * (h * 0.6) + (h * 0.1);
                    this.direction = Math.random() > 0.5 ? 1 : -1;
                    this.x = this.direction === 1 ? -50 : w + 50;
                    this.speed = Math.random() * 100 + 150; // pixels per second
                    this.size = 30;
                    this.flapTime = 0;
                    this.color = `hsl(${Math.random() * 360}, 80%, 50%)`;
                }

                update(dt) {
                    this.x += this.speed * this.direction * dt;
                    this.flapTime += dt * 10;
                    this.y += Math.sin(this.flapTime) * 2; // wavy motion
                }

                draw(ctx) {
                    ctx.save();
                    ctx.translate(this.x, this.y);
                    if (this.direction === -1) {
                        ctx.scale(-1, 1);
                    }
                    
                    // Body
                    ctx.fillStyle = this.color;
                    ctx.beginPath();
                    ctx.ellipse(0, 0, this.size, this.size * 0.6, 0, 0, Math.PI * 2);
                    ctx.fill();

                    // Head
                    ctx.beginPath();
                    ctx.arc(this.size * 0.8, -this.size * 0.2, this.size * 0.5, 0, Math.PI * 2);
                    ctx.fill();

                    // Beak
                    ctx.fillStyle = 'orange';
                    ctx.beginPath();
                    ctx.moveTo(this.size * 1.2, -this.size * 0.2);
                    ctx.lineTo(this.size * 1.8, -this.size * 0.1);
                    ctx.lineTo(this.size * 1.2, 0);
                    ctx.fill();

                    // Wing
                    ctx.fillStyle = 'rgba(0,0,0,0.3)';
                    ctx.beginPath();
                    const wingY = Math.sin(this.flapTime) * this.size;
                    ctx.ellipse(0, 0, this.size * 0.6, Math.abs(wingY) + 5, 0, 0, Math.PI * 2);
                    ctx.fill();

                    // Eye
                    ctx.fillStyle = 'white';
                    ctx.beginPath();
                    ctx.arc(this.size * 0.9, -this.size * 0.3, 4, 0, Math.PI*2);
                    ctx.fill();
                    ctx.fillStyle = 'black';
                    ctx.beginPath();
                    ctx.arc(this.size * 0.95, -this.size * 0.3, 2, 0, Math.PI*2);
                    ctx.fill();

                    ctx.restore();
                }

                isOffscreen() {
                    return (this.direction === 1 && this.x > w + 100) || (this.direction === -1 && this.x < -100);
                }
            }

            class Particle {
                constructor(x, y, color) {
                    this.x = x;
                    this.y = y;
                    this.vx = (Math.random() - 0.5) * 400;
                    this.vy = (Math.random() - 0.5) * 400;
                    this.life = 1.0;
                    this.color = color;
                    this.size = Math.random() * 5 + 3;
                }
                update(dt) {
                    this.x += this.vx * dt;
                    this.y += this.vy * dt;
                    this.life -= dt * 2;
                }
                draw(ctx) {
                    ctx.globalAlpha = Math.max(0, this.life);
                    ctx.fillStyle = this.color;
                    ctx.beginPath();
                    ctx.arc(this.x, this.y, this.size, 0, Math.PI * 2);
                    ctx.fill();
                    ctx.globalAlpha = 1.0;
                }
            }

            function startGame() {
                score = 0;
                timeLeft = 60;
                birds = [];
                particles = [];
                isPlaying = true;
                lastTime = performance.now();
                document.getElementById('score').innerText = score;
                document.getElementById('time').innerText = timeLeft;
                document.getElementById('start-screen').style.display = 'none';
                document.getElementById('game-over').style.display = 'none';
                
                if (gameInterval) clearInterval(gameInterval);
                gameInterval = setInterval(() => {
                    timeLeft--;
                    document.getElementById('time').innerText = timeLeft;
                    if (timeLeft <= 0) {
                        endGame();
                    }
                }, 1000);
                
                requestAnimationFrame(loop);
            }

            function endGame() {
                isPlaying = false;
                clearInterval(gameInterval);
                document.getElementById('game-over').style.display = 'flex';
                document.getElementById('final-score').innerText = score;
            }

            canvas.addEventListener('mousedown', (e) => {
                if (!isPlaying) return;
                
                const rect = canvas.getBoundingClientRect();
                const mouseX = e.clientX - rect.left;
                const mouseY = e.clientY - rect.top;

                let hit = false;
                for (let i = birds.length - 1; i >= 0; i--) {
                    const b = birds[i];
                    const dx = mouseX - b.x;
                    const dy = mouseY - b.y;
                    // Check collision roughly using a circle
                    if (dx * dx + dy * dy < b.size * b.size * 2) {
                        hit = true;
                        score += 10;
                        document.getElementById('score').innerText = score;
                        
                        // spawn particles
                        for(let p=0; p<15; p++) {
                            particles.push(new Particle(b.x, b.y, b.color));
                        }
                        
                        birds.splice(i, 1);
                        break; // only hit one bird per shot
                    }
                }
            });

            function loop(time) {
                if (!isPlaying) return;

                const dt = (time - lastTime) / 1000;
                lastTime = time;

                // Clear screen
                ctx.clearRect(0, 0, w, h);

                // Draw background landscape hints (clouds/mountains)
                drawBackground();

                // Spawn birds
                spawnTimer -= dt;
                if (spawnTimer <= 0) {
                    birds.push(new Bird());
                    spawnTimer = Math.random() * 1 + 0.5; // Spawn every 0.5 to 1.5 seconds
                }

                // Update and draw birds
                for (let i = birds.length - 1; i >= 0; i--) {
                    const b = birds[i];
                    b.update(dt);
                    b.draw(ctx);
                    if (b.isOffscreen()) {
                        birds.splice(i, 1);
                    }
                }

                // Update and draw particles
                for (let i = particles.length - 1; i >= 0; i--) {
                    const p = particles[i];
                    p.update(dt);
                    p.draw(ctx);
                    if (p.life <= 0) {
                        particles.splice(i, 1);
                    }
                }

                requestAnimationFrame(loop);
            }

            function drawBackground() {
                // Clouds
                ctx.fillStyle = 'rgba(255,255,255,0.4)';
                ctx.beginPath();
                ctx.arc(w*0.2, h*0.2, 50, 0, Math.PI*2);
                ctx.arc(w*0.25, h*0.2, 60, 0, Math.PI*2);
                ctx.arc(w*0.3, h*0.2, 40, 0, Math.PI*2);
                ctx.fill();

                ctx.beginPath();
                ctx.arc(w*0.7, h*0.3, 40, 0, Math.PI*2);
                ctx.arc(w*0.75, h*0.25, 70, 0, Math.PI*2);
                ctx.arc(w*0.8, h*0.3, 50, 0, Math.PI*2);
                ctx.fill();

                // Ground
                ctx.fillStyle = '#4CAF50';
                ctx.beginPath();
                ctx.moveTo(0, h);
                ctx.lineTo(0, h * 0.8);
                ctx.quadraticCurveTo(w * 0.5, h * 0.7, w, h * 0.85);
                ctx.lineTo(w, h);
                ctx.fill();
            }

            document.getElementById('start-btn').addEventListener('click', startGame);
            document.getElementById('restart-btn').addEventListener('click', startGame);

        </script>
    </body>
</html>