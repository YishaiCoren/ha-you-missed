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
            .stat { font-size: 28px; font-weight: bold; color: white; text-shadow: 2px 2px 4px rgba(0,0,0,0.5); }
            #game-over { display: none; position: absolute; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); color: white; flex-direction: column; align-items: center; justify-content: center; z-index: 10; }
            #game-over h1 { font-size: 64px; margin-bottom: 20px; }
            #start-screen { position: absolute; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); color: white; display: flex; flex-direction: column; align-items: center; justify-content: center; z-index: 20; }
        </style>
    </head>
    <body class="antialiased font-sans">
        
        <div id="ui">
            <div class="stat">Score: <span id="score">0</span></div>
            <div class="stat">Time: <span id="time">60</span>s</div>
        </div>

        <div id="start-screen">
            <h1 class="text-6xl font-bold mb-4">Bird Hunter</h1>
            <p class="mb-8 text-2xl text-center max-w-2xl">
                Click to shoot the birds. You have 60 seconds!<br>
                <span class="text-lg text-gray-300 mt-2 block">Small Red Birds = 30pts | Medium Colorful Birds = 10pts | Big Gray Birds = 5pts</span>
            </p>
            <button id="start-btn" class="btn btn-primary btn-lg px-8 text-xl">Start Game</button>
        </div>

        <div id="game-over">
            <h1 class="text-6xl font-bold text-center">Time's Up!</h1>
            <p class="text-4xl mb-8">Final Score: <span id="final-score">0</span></p>
            <button id="restart-btn" class="btn btn-primary btn-lg px-8 text-xl">Play Again</button>
        </div>

        <canvas id="gameCanvas"></canvas>

        <script>
            const canvas = document.getElementById('gameCanvas');
            const ctx = canvas.getContext('2d');
            
            let w, h;
            let birds = [];
            let particles = [];
            let floatingTexts = [];
            let score = 0;
            let timeLeft = 60;
            let isPlaying = false;
            let lastTime = 0;
            let spawnTimer = 0;
            let gameInterval = null;

            // Audio Context for sound effects
            const AudioContext = window.AudioContext || window.webkitAudioContext;
            let audioCtx = null;

            function initAudio() {
                if (!audioCtx) {
                    audioCtx = new AudioContext();
                }
                if (audioCtx.state === 'suspended') {
                    audioCtx.resume();
                }
            }

            function playShotSound() {
                if (!audioCtx) return;
                const osc = audioCtx.createOscillator();
                const gain = audioCtx.createGain();
                
                osc.type = 'square';
                osc.frequency.setValueAtTime(150, audioCtx.currentTime);
                osc.frequency.exponentialRampToValueAtTime(0.01, audioCtx.currentTime + 0.1);
                
                gain.gain.setValueAtTime(0.1, audioCtx.currentTime);
                gain.gain.exponentialRampToValueAtTime(0.01, audioCtx.currentTime + 0.1);
                
                osc.connect(gain);
                gain.connect(audioCtx.destination);
                osc.start();
                osc.stop(audioCtx.currentTime + 0.1);
            }

            function playHitSound() {
                if (!audioCtx) return;
                const osc = audioCtx.createOscillator();
                const gain = audioCtx.createGain();
                
                osc.type = 'triangle';
                osc.frequency.setValueAtTime(800, audioCtx.currentTime);
                osc.frequency.exponentialRampToValueAtTime(400, audioCtx.currentTime + 0.1);
                
                gain.gain.setValueAtTime(0.1, audioCtx.currentTime);
                gain.gain.exponentialRampToValueAtTime(0.01, audioCtx.currentTime + 0.1);
                
                osc.connect(gain);
                gain.connect(audioCtx.destination);
                osc.start();
                osc.stop(audioCtx.currentTime + 0.1);
            }

            function resize() {
                w = canvas.width = window.innerWidth;
                h = canvas.height = window.innerHeight;
            }
            window.addEventListener('resize', resize);
            resize();

            class FloatingText {
                constructor(x, y, text, color) {
                    this.x = x;
                    this.y = y;
                    this.text = text;
                    this.color = color;
                    this.life = 1.0;
                    this.vy = -60; // Float upwards
                }
                update(dt) {
                    this.y += this.vy * dt;
                    this.life -= dt * 1.5; // Fade out speed
                }
                draw(ctx) {
                    ctx.save();
                    ctx.globalAlpha = Math.max(0, this.life);
                    ctx.fillStyle = this.color;
                    ctx.font = "bold 24px sans-serif";
                    ctx.shadowColor = "rgba(0,0,0,0.5)";
                    ctx.shadowBlur = 4;
                    ctx.shadowOffsetX = 2;
                    ctx.shadowOffsetY = 2;
                    ctx.fillText(this.text, this.x, this.y);
                    ctx.restore();
                }
            }

            class Bird {
                constructor() {
                    this.y = Math.random() * (h * 0.6) + (h * 0.1);
                    this.baseY = this.y;
                    this.direction = Math.random() > 0.5 ? 1 : -1;
                    this.x = this.direction === 1 ? -100 : w + 100;
                    
                    const typeRoll = Math.random();
                    if (typeRoll > 0.8) {
                        // Small fast bird
                        this.speed = Math.random() * 150 + 250; // 250 to 400
                        this.size = 18;
                        this.points = 30;
                        this.color = '#ff3333';
                    } else if (typeRoll > 0.3) {
                        // Medium bird
                        this.speed = Math.random() * 100 + 150; // 150 to 250
                        this.size = 28;
                        this.points = 10;
                        this.color = `hsl(${Math.random() * 360}, 80%, 50%)`;
                    } else {
                        // Large slow bird
                        this.speed = Math.random() * 50 + 80; // 80 to 130
                        this.size = 45;
                        this.points = 5;
                        this.color = '#555555';
                    }
                    
                    this.flapTime = 0;
                }

                update(dt) {
                    this.x += this.speed * this.direction * dt;
                    this.flapTime += dt * (this.speed / 15);
                    this.y = this.baseY + Math.sin(this.flapTime * 0.2) * (this.size * 1.5); // wavy motion
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
                    ctx.fillStyle = '#ffaa00';
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
                    ctx.arc(this.size * 0.9, -this.size * 0.3, this.size * 0.15, 0, Math.PI*2);
                    ctx.fill();
                    ctx.fillStyle = 'black';
                    ctx.beginPath();
                    ctx.arc(this.size * 0.95, -this.size * 0.3, this.size * 0.08, 0, Math.PI*2);
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
                    this.vx = (Math.random() - 0.5) * 500;
                    this.vy = (Math.random() - 0.5) * 500;
                    this.life = 1.0;
                    this.color = color;
                    this.size = Math.random() * 6 + 3;
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
                initAudio();
                score = 0;
                timeLeft = 60;
                birds = [];
                particles = [];
                floatingTexts = [];
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
                
                initAudio();
                playShotSound();

                const rect = canvas.getBoundingClientRect();
                const mouseX = e.clientX - rect.left;
                const mouseY = e.clientY - rect.top;

                let hit = false;
                // Check in reverse so we hit the one drawn on top
                for (let i = birds.length - 1; i >= 0; i--) {
                    const b = birds[i];
                    const dx = mouseX - b.x;
                    const dy = mouseY - b.y;
                    
                    if (dx * dx + dy * dy < b.size * b.size * 1.5) {
                        hit = true;
                        score += b.points;
                        document.getElementById('score').innerText = score;
                        
                        playHitSound();

                        // spawn floating text
                        floatingTexts.push(new FloatingText(b.x, b.y, "+" + b.points, "#00ff00"));

                        // spawn particles
                        for(let p=0; p<20; p++) {
                            particles.push(new Particle(b.x, b.y, b.color));
                        }
                        
                        birds.splice(i, 1);
                        break; 
                    }
                }
            });

            function loop(time) {
                if (!isPlaying) return;

                const dt = (time - lastTime) / 1000;
                lastTime = time;

                // Clear screen
                ctx.clearRect(0, 0, w, h);

                // Draw landscape hints
                drawBackground();

                // Spawn birds
                spawnTimer -= dt;
                if (spawnTimer <= 0) {
                    birds.push(new Bird());
                    spawnTimer = Math.random() * 0.8 + 0.3; // Spawn faster: every 0.3 to 1.1s
                }

                // Update & draw birds
                for (let i = birds.length - 1; i >= 0; i--) {
                    const b = birds[i];
                    b.update(dt);
                    b.draw(ctx);
                    if (b.isOffscreen()) {
                        birds.splice(i, 1);
                    }
                }

                // Update & draw particles
                for (let i = particles.length - 1; i >= 0; i--) {
                    const p = particles[i];
                    p.update(dt);
                    p.draw(ctx);
                    if (p.life <= 0) {
                        particles.splice(i, 1);
                    }
                }

                // Update & draw floating texts
                for (let i = floatingTexts.length - 1; i >= 0; i--) {
                    const ft = floatingTexts[i];
                    ft.update(dt);
                    ft.draw(ctx);
                    if (ft.life <= 0) {
                        floatingTexts.splice(i, 1);
                    }
                }

                requestAnimationFrame(loop);
            }

            function drawBackground() {
                // Clouds
                ctx.fillStyle = 'rgba(255,255,255,0.6)';
                ctx.beginPath();
                ctx.arc(w*0.2, h*0.2, 60, 0, Math.PI*2);
                ctx.arc(w*0.25, h*0.2, 80, 0, Math.PI*2);
                ctx.arc(w*0.3, h*0.2, 50, 0, Math.PI*2);
                ctx.fill();

                ctx.beginPath();
                ctx.arc(w*0.7, h*0.3, 50, 0, Math.PI*2);
                ctx.arc(w*0.75, h*0.25, 90, 0, Math.PI*2);
                ctx.arc(w*0.8, h*0.3, 60, 0, Math.PI*2);
                ctx.fill();

                // Sun
                ctx.fillStyle = '#FFD700';
                ctx.beginPath();
                ctx.arc(w*0.9, h*0.15, 40, 0, Math.PI*2);
                ctx.fill();

                // Ground
                ctx.fillStyle = '#4CAF50';
                ctx.beginPath();
                ctx.moveTo(0, h);
                ctx.lineTo(0, h * 0.8);
                ctx.quadraticCurveTo(w * 0.5, h * 0.65, w, h * 0.85);
                ctx.lineTo(w, h);
                ctx.fill();
            }

            document.getElementById('start-btn').addEventListener('click', startGame);
            document.getElementById('restart-btn').addEventListener('click', startGame);

        </script>
    </body>
</html>