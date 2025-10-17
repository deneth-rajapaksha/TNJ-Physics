// starfield.js
// Lightweight animated starfield — small footprint and easy to reuse
(function () {
  const canvas = document.getElementById('starfield');
  if (!canvas) return;
  const ctx = canvas.getContext('2d');
  let w, h, stars = [];

  const DPR = Math.max(1, window.devicePixelRatio || 1);
  function resize(){
    w = canvas.width = Math.floor(window.innerWidth * DPR);
    h = canvas.height = Math.floor(window.innerHeight * DPR);
    canvas.style.width = window.innerWidth + 'px';
    canvas.style.height = window.innerHeight + 'px';
    ctx.scale(DPR, DPR);
    initStars();
  }

  function initStars(){
    stars = [];
    const count = Math.floor((window.innerWidth * window.innerHeight) / 8000);
    for (let i=0;i<count;i++){
      stars.push({
        x: Math.random() * window.innerWidth,
        y: Math.random() * window.innerHeight,
        r: Math.random()*1.2 + 0.2,
        alpha: Math.random()*0.9,
        twinkle: Math.random()*0.05 + 0.01,
        vx: (Math.random()-0.5)*0.02
      });
    }
  }

  function draw(){
    ctx.clearRect(0,0,canvas.width,canvas.height);
    // subtle dark-gray gradient behind for the gray area effect
    const g = ctx.createLinearGradient(0,0,0,window.innerHeight);
    g.addColorStop(0,'rgba(8,8,12,0.9)');
    g.addColorStop(1,'rgba(6,6,10,0.95)');
    ctx.fillStyle = g;
    ctx.fillRect(0,0,window.innerWidth,window.innerHeight);

    // draw stars
    for (let s of stars){
      s.alpha += (Math.random()-0.5)*s.twinkle;
      s.alpha = Math.max(0.15, Math.min(1, s.alpha));
      s.x += s.vx;
      if (s.x < 0) s.x = window.innerWidth;
      if (s.x > window.innerWidth) s.x = 0;

      ctx.beginPath();
      ctx.globalAlpha = s.alpha * 0.9;
      ctx.fillStyle = 'white';
      ctx.arc(s.x, s.y, s.r, 0, Math.PI*2);
      ctx.fill();
    }
    ctx.globalAlpha = 1;
    requestAnimationFrame(draw);
  }

  window.addEventListener('resize', () => {
    // debounce quickly
    clearTimeout(window.__starResize);
    window.__starResize = setTimeout(resize, 120);
  });

  resize();
  draw();
})();
