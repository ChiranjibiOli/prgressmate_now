<?php
session_start();


?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ProgressMate — Track Your Academic Success</title>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,600;9..144,700;9..144,900&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
:root{
  --bg:#faf8f4;
  --bg2:#f3f0ea;
  --white:#ffffff;
  --ink:#1a1207;
  --ink2:#3d3320;
  --muted:#7a6e5f;
  --muted2:#b5a898;
  --rim:#e8e2d9;
  --rim2:#d6cfc4;
  --card:#ffffff;
  --card2:#f7f4ef;
  --teal:#007a6e;
  --teal-lt:#e0f5f2;
  --amber:#d97706;
  --amber-lt:#fef3c7;
  --rose:#c0392b;
  --rose-lt:#fde8e6;
  --indigo:#4338ca;
  --indigo-lt:#ede9fe;
  --lime:#3d7a00;
  --lime-lt:#ecfce8;
}
html{scroll-behavior:smooth;}
body{
  font-family:'Plus Jakarta Sans',sans-serif;
  background:var(--bg);color:var(--ink);
  overflow-x:hidden;line-height:1.6;
}

/* ── TEXTURE OVERLAY ── */
body::before{
  content:'';position:fixed;inset:0;z-index:0;pointer-events:none;opacity:.35;
  background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='4' height='4'%3E%3Crect width='4' height='4' fill='%23f5f0e8'/%3E%3Ccircle cx='1' cy='1' r='.5' fill='%23e8dfd0' opacity='.6'/%3E%3C/svg%3E");
}
/* soft warm blobs */
body::after{
  content:'';position:fixed;inset:0;z-index:0;pointer-events:none;
  background:
    radial-gradient(ellipse 70% 50% at 5% 0%,rgba(0,122,110,.06) 0%,transparent 55%),
    radial-gradient(ellipse 60% 45% at 95% 10%,rgba(217,119,6,.07) 0%,transparent 50%),
    radial-gradient(ellipse 50% 60% at 50% 105%,rgba(67,56,202,.05) 0%,transparent 55%);
}

.container{max-width:1180px;margin:0 auto;padding:0 28px;position:relative;z-index:2;}

/* ════════════════════════
   NAVBAR
════════════════════════ */
.navbar{
  position:fixed;top:0;left:0;right:0;z-index:900;
  padding:0 28px;
  background:rgba(250,248,244,.90);
  border-bottom:1px solid var(--rim);
  backdrop-filter:blur(20px);-webkit-backdrop-filter:blur(20px);
}
.nav-inner{
  max-width:1180px;margin:0 auto;
  display:flex;align-items:center;justify-content:space-between;
  height:66px;gap:20px;
}
.logo{display:flex;align-items:center;gap:11px;text-decoration:none;flex-shrink:0;}
.logo-icon{
  width:38px;height:38px;border-radius:12px;flex-shrink:0;
  display:grid;place-items:center;font-size:16px;
  background:linear-gradient(135deg,var(--teal),#00a896);
  color:#fff;box-shadow:0 6px 18px rgba(0,122,110,.22);
}
.logo-name{font-family:'Fraunces',serif;font-weight:900;font-size:20px;color:var(--ink);letter-spacing:-.4px;}
.logo-name em{font-style:italic;color:var(--teal);}

.nav-links{display:flex;align-items:center;gap:2px;}
.nl{padding:8px 14px;border-radius:10px;font-size:14px;font-weight:600;color:var(--muted);text-decoration:none;transition:.18s;}
.nl:hover,.nl.on{color:var(--ink);background:var(--bg2);}

.nav-act{display:flex;align-items:center;gap:10px;}
.nb{padding:9px 18px;border-radius:12px;font-size:14px;font-weight:700;text-decoration:none;transition:.2s;font-family:'Plus Jakarta Sans',sans-serif;letter-spacing:-.1px;}
.nb-ghost{border:1.5px solid var(--rim2);color:var(--ink);background:transparent;}
.nb-ghost:hover{background:var(--bg2);}
.nb-fill{
  background:var(--teal);color:#fff;border:none;
  box-shadow:0 8px 22px rgba(0,122,110,.25);
}
.nb-fill:hover{transform:translateY(-2px);box-shadow:0 14px 30px rgba(0,122,110,.32);background:#006b60;}

.nav-burger{
  display:none;width:42px;height:42px;border-radius:11px;
  border:1.5px solid var(--rim2);background:var(--white);
  color:var(--ink);cursor:pointer;place-items:center;font-size:15px;
}
.nav-drawer{
  display:none;flex-direction:column;gap:4px;
  padding:14px 20px 22px;
  border-top:1px solid var(--rim);
  background:rgba(250,248,244,.97);
}
.nav-drawer.show{display:flex;}
.nav-drawer .nl{padding:13px 14px;font-size:15px;}
.nav-drawer .nb{text-align:center;padding:13px;margin-top:4px;}
@media(max-width:780px){.nav-links,.nav-act{display:none;}.nav-burger{display:grid;}}

/* ════════════════════════
   HERO
════════════════════════ */
.hero{
  min-height:100vh;display:flex;align-items:center;
  padding:110px 0 72px;
}
.hero-grid{display:grid;grid-template-columns:1fr 460px;gap:60px;align-items:center;}

.hero-pill{
  display:inline-flex;align-items:center;gap:8px;
  padding:6px 14px 6px 8px;border-radius:999px;
  border:1.5px solid rgba(0,122,110,.20);
  background:var(--teal-lt);
  font-size:12px;font-weight:700;color:var(--teal);
  margin-bottom:22px;letter-spacing:.2px;
}
.hp-dot{
  width:22px;height:22px;border-radius:50%;
  display:grid;place-items:center;
  background:rgba(0,122,110,.15);font-size:10px;color:var(--teal);
}

.hero-h1{
  font-family:'Fraunces',serif;
  font-size:clamp(42px,6vw,72px);
  font-weight:900;line-height:1.04;
  letter-spacing:-2px;margin-bottom:22px;color:var(--ink);
}
.hero-h1 .glow{
  background:linear-gradient(135deg,var(--teal) 0%,#00a896 50%,var(--amber) 100%);
  -webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;
}

.hero-p{font-size:16.5px;color:var(--muted);line-height:1.75;max-width:490px;margin-bottom:34px;}

.hero-btns{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:50px;}
.hbtn{
  display:inline-flex;align-items:center;gap:10px;
  padding:15px 28px;border-radius:14px;
  font-size:15px;font-weight:800;text-decoration:none;
  transition:.22s;font-family:'Plus Jakarta Sans',sans-serif;letter-spacing:-.2px;
}
.hbtn-main{
  background:var(--teal);color:#fff;
  box-shadow:0 12px 32px rgba(0,122,110,.28);
}
.hbtn-main:hover{transform:translateY(-3px);box-shadow:0 20px 44px rgba(0,122,110,.36);background:#006b60;}
.hbtn-out{
  border:2px solid var(--rim2);background:var(--white);color:var(--ink);
  box-shadow:0 4px 14px rgba(0,0,0,.06);
}
.hbtn-out:hover{background:var(--bg2);border-color:var(--rim2);}

.hero-nums{display:flex;gap:36px;flex-wrap:wrap;}
.hn-val{font-family:'Fraunces',serif;font-size:28px;font-weight:900;letter-spacing:-1px;color:var(--ink);}
.hn-val span{color:var(--teal);}
.hn-lbl{font-size:12px;color:var(--muted);font-weight:600;margin-top:2px;}
.hn-div{width:1px;background:var(--rim2);align-self:stretch;margin:4px 0;}

/* ── Hero card ── */
.hv-card{
  border-radius:24px;
  border:1.5px solid var(--rim);
  background:var(--white);
  box-shadow:0 32px 80px rgba(26,18,7,.10),0 8px 24px rgba(26,18,7,.06);
  overflow:hidden;
}
.hv-bar{
  display:flex;align-items:center;justify-content:space-between;
  padding:15px 20px;
  border-bottom:1.5px solid var(--rim);
  background:var(--card2);
}
.hv-title{font-family:'Fraunces',serif;font-weight:700;font-size:15px;color:var(--ink);}
.hv-dots{display:flex;gap:6px;}
.hv-dot{width:11px;height:11px;border-radius:50%;}
.hv-body{padding:20px;}
.hv-kpis{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:14px;}
.kpi{padding:14px 16px;border-radius:14px;border:1.5px solid var(--rim);background:var(--bg);}
.kpi-n{font-family:'Fraunces',serif;font-size:26px;font-weight:900;letter-spacing:-1px;margin-bottom:2px;}
.kpi-l{font-size:11px;color:var(--muted);font-weight:600;}
.hv-goals{display:flex;flex-direction:column;gap:9px;}
.hg{padding:12px 14px;border-radius:12px;border:1.5px solid var(--rim);background:var(--bg);}
.hg-top{display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;}
.hg-name{font-size:12.5px;font-weight:700;color:var(--ink2);}
.hg-pct{font-size:11.5px;font-weight:800;color:var(--teal);}
.hg-bar{height:6px;border-radius:999px;background:var(--rim);overflow:hidden;}
.hg-fill{height:100%;border-radius:999px;}

.hv-badge{
  position:absolute;bottom:-18px;left:-18px;
  padding:11px 17px;border-radius:16px;
  border:1.5px solid rgba(217,119,6,.22);
  background:var(--amber-lt);
  font-size:12.5px;font-weight:800;color:var(--amber);
  display:flex;align-items:center;gap:9px;
  box-shadow:0 12px 32px rgba(26,18,7,.12);
  animation:bob 3.5s ease-in-out infinite;
}
@keyframes bob{0%,100%{transform:translateY(0);}50%{transform:translateY(-8px);}}

.hero-vis{position:relative;}
@media(max-width:940px){.hero-grid{grid-template-columns:1fr;}.hero-vis{display:none;}}

/* ════════════════════════
   SECTIONS
════════════════════════ */
.sec{padding:96px 0;}
.sec-alt{background:var(--bg2);}
.sh{text-align:center;margin-bottom:60px;}
.stag{
  display:inline-flex;align-items:center;gap:7px;
  padding:5px 13px;border-radius:999px;
  border:1.5px solid var(--rim2);background:var(--white);
  font-size:11.5px;font-weight:700;color:var(--muted);
  margin-bottom:16px;text-transform:uppercase;letter-spacing:.5px;
}
.sh-title{
  font-family:'Fraunces',serif;
  font-size:clamp(30px,4vw,48px);font-weight:900;
  line-height:1.1;margin-bottom:14px;letter-spacing:-1.5px;color:var(--ink);
}
.sh-sub{font-size:16px;color:var(--muted);max-width:560px;margin:0 auto;line-height:1.72;}

/* ── FEATURES ── */
.feat-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;}
.fc{
  padding:30px;border-radius:20px;
  border:1.5px solid var(--rim);background:var(--white);
  transition:.25s;position:relative;overflow:hidden;
  box-shadow:0 2px 12px rgba(26,18,7,.04);
}
.fc:hover{transform:translateY(-5px);border-color:var(--rim2);box-shadow:0 20px 50px rgba(26,18,7,.10);}
.fc-icon{
  width:52px;height:52px;border-radius:16px;
  display:grid;place-items:center;font-size:20px;
  margin-bottom:20px;border:1.5px solid transparent;
}
.fc-t{font-family:'Fraunces',serif;font-size:18px;font-weight:700;margin-bottom:10px;color:var(--ink);letter-spacing:-.3px;}
.fc-d{font-size:13.5px;color:var(--muted);line-height:1.68;}
@media(max-width:900px){.feat-grid{grid-template-columns:1fr 1fr;}}
@media(max-width:560px){.feat-grid{grid-template-columns:1fr;}}

/* ── HOW IT WORKS ── */
.how-wrap{
  border-radius:28px;border:1.5px solid var(--rim);
  background:var(--white);padding:56px 48px;
  box-shadow:0 4px 24px rgba(26,18,7,.05);
}
.steps{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;position:relative;}
.steps::before{
  content:'';position:absolute;top:34px;
  left:calc(16.66% + 24px);right:calc(16.66% + 24px);
  height:2px;
  background:linear-gradient(90deg,var(--teal),var(--amber));
  opacity:.25;border-radius:999px;
}
.step{text-align:center;padding:26px 18px;}
.sn{
  width:68px;height:68px;border-radius:50%;
  display:grid;place-items:center;margin:0 auto 20px;
  font-family:'Fraunces',serif;font-size:24px;font-weight:900;
  border:2px solid rgba(0,122,110,.20);
  background:var(--teal-lt);color:var(--teal);
  box-shadow:0 8px 24px rgba(0,122,110,.14);
}
.st-t{font-family:'Fraunces',serif;font-size:17px;font-weight:700;margin-bottom:10px;color:var(--ink);}
.st-d{font-size:13.5px;color:var(--muted);line-height:1.68;}
@media(max-width:680px){.how-wrap{padding:36px 22px;}.steps{grid-template-columns:1fr;}.steps::before{display:none;}}

/* ── TESTIMONIALS ── */
.tg{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;}
.tc{
  padding:28px;border-radius:20px;border:1.5px solid var(--rim);
  background:var(--white);transition:.22s;
  box-shadow:0 2px 12px rgba(26,18,7,.04);
}
.tc:hover{transform:translateY(-4px);box-shadow:0 18px 44px rgba(26,18,7,.09);}
.tc-q{font-size:14px;color:var(--muted);line-height:1.75;margin-bottom:22px;font-style:italic;}
.tc-q::before{content:'"';font-family:'Fraunces',serif;font-size:56px;line-height:.3;display:block;margin-bottom:14px;font-style:normal;color:var(--teal);opacity:.6;}
.tc-auth{display:flex;align-items:center;gap:12px;}
.tc-av{width:44px;height:44px;border-radius:50%;object-fit:cover;border:2px solid var(--rim2);}
.tc-name{font-size:14px;font-weight:800;color:var(--ink);}
.tc-role{font-size:12px;color:var(--muted);}
@media(max-width:860px){.tg{grid-template-columns:1fr 1fr;}}
@media(max-width:540px){.tg{grid-template-columns:1fr;}}

/* ── ABOUT ── */
.about-wrap{display:grid;grid-template-columns:1fr 1fr;gap:64px;align-items:center;}
.ab-txt p{font-size:15.5px;color:var(--muted);line-height:1.78;margin-bottom:16px;}
.ab-tags{display:flex;flex-wrap:wrap;gap:9px;margin-top:26px;}
.ab-tag{
  padding:7px 16px;border-radius:999px;
  border:1.5px solid var(--rim2);background:var(--white);
  font-size:12px;font-weight:700;color:var(--muted);
  display:inline-flex;align-items:center;gap:7px;
  box-shadow:0 2px 8px rgba(26,18,7,.04);
}
.ab-nums{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
.an{
  padding:24px;border-radius:20px;text-align:center;
  border:1.5px solid var(--rim);background:var(--white);
  box-shadow:0 2px 12px rgba(26,18,7,.04);
  transition:.2s;
}
.an:hover{transform:translateY(-3px);box-shadow:0 12px 30px rgba(26,18,7,.08);}
.an-v{font-family:'Fraunces',serif;font-size:32px;font-weight:900;letter-spacing:-1.5px;color:var(--teal);}
.an-l{font-size:12px;color:var(--muted);margin-top:5px;font-weight:600;}
@media(max-width:800px){.about-wrap{grid-template-columns:1fr;}}

/* ── CONTACT ── */
.cform{
  max-width:580px;margin:0 auto;padding:40px;border-radius:24px;
  border:1.5px solid var(--rim);background:var(--white);
  box-shadow:0 8px 40px rgba(26,18,7,.07);
}
.fg{margin-bottom:18px;}
.fg label{display:block;font-size:13px;font-weight:700;margin-bottom:7px;color:var(--ink2);}
.fg input,.fg textarea{
  width:100%;padding:13px 15px;border-radius:12px;
  border:1.5px solid var(--rim2);background:var(--bg);
  color:var(--ink);font-family:'Plus Jakarta Sans',sans-serif;font-size:14px;outline:none;transition:.18s;
}
.fg input::placeholder,.fg textarea::placeholder{color:var(--muted2);}
.fg input:focus,.fg textarea:focus{border-color:rgba(0,122,110,.40);box-shadow:0 0 0 3px rgba(0,122,110,.08);background:var(--white);}
.fg textarea{resize:vertical;min-height:115px;}
.ok-msg{padding:13px 16px;border-radius:12px;border:1.5px solid rgba(61,122,0,.22);background:var(--lime-lt);color:var(--lime);margin-bottom:16px;font-size:14px;font-weight:600;}
.err-msg{padding:13px 16px;border-radius:12px;border:1.5px solid rgba(192,57,43,.22);background:var(--rose-lt);color:var(--rose);margin-bottom:16px;font-size:14px;font-weight:600;}

/* ── CTA ── */
.cta-band{padding:96px 0;}
.cta-box{
  text-align:center;padding:70px 44px;border-radius:32px;
  border:1.5px solid var(--rim);
  background:linear-gradient(145deg,#e8f5f3 0%,#fef8f0 50%,#f0eeff 100%);
  position:relative;overflow:hidden;
  box-shadow:0 12px 60px rgba(26,18,7,.07);
}
.cta-box::before{
  content:'';position:absolute;top:-60px;right:-60px;
  width:260px;height:260px;border-radius:50%;
  background:radial-gradient(circle,rgba(0,122,110,.10),transparent 65%);
  pointer-events:none;
}
.cta-box::after{
  content:'';position:absolute;bottom:-40px;left:-40px;
  width:200px;height:200px;border-radius:50%;
  background:radial-gradient(circle,rgba(217,119,6,.08),transparent 65%);
  pointer-events:none;
}
.cta-title{
  font-family:'Fraunces',serif;
  font-size:clamp(28px,4vw,50px);font-weight:900;
  margin-bottom:16px;letter-spacing:-1.5px;color:var(--ink);
  position:relative;z-index:1;
}
.cta-title span{color:var(--teal);}
.cta-sub{font-size:16px;color:var(--muted);margin-bottom:36px;max-width:520px;margin-left:auto;margin-right:auto;position:relative;z-index:1;}
.cta-btns{display:flex;gap:14px;justify-content:center;flex-wrap:wrap;margin-bottom:22px;position:relative;z-index:1;}
.cta-note{font-size:12.5px;color:var(--muted2);position:relative;z-index:1;}

/* ── FOOTER ── */
.footer{padding:64px 0 32px;border-top:1.5px solid var(--rim);background:var(--bg2);}
.fg-grid{display:grid;grid-template-columns:2fr 1fr 1fr 1fr;gap:44px;margin-bottom:52px;}
.fb-logo{display:flex;align-items:center;gap:10px;text-decoration:none;margin-bottom:14px;}
.fb-name{font-family:'Fraunces',serif;font-weight:900;font-size:19px;color:var(--ink);}
.fb-name em{font-style:italic;color:var(--teal);}
.fb-desc{font-size:13.5px;color:var(--muted);line-height:1.7;margin-bottom:20px;}
.socials{display:flex;gap:9px;}
.sc{
  width:36px;height:36px;border-radius:10px;display:grid;place-items:center;
  border:1.5px solid var(--rim2);background:var(--white);
  color:var(--muted);font-size:13px;text-decoration:none;transition:.18s;
}
.sc:hover{color:var(--teal);border-color:rgba(0,122,110,.28);}
.fc-col h4{font-family:'Plus Jakarta Sans',sans-serif;font-size:11.5px;font-weight:800;margin-bottom:15px;letter-spacing:.5px;text-transform:uppercase;color:var(--muted2);}
.fl{display:block;font-size:14px;color:var(--muted);text-decoration:none;margin-bottom:9px;transition:.15s;font-weight:500;}
.fl:hover{color:var(--ink);}
.foot-bot{
  display:flex;align-items:center;justify-content:space-between;
  padding-top:26px;border-top:1.5px solid var(--rim);
  font-size:13px;color:var(--muted2);flex-wrap:wrap;gap:12px;
}
.foot-blinks{display:flex;gap:18px;}
@media(max-width:900px){.fg-grid{grid-template-columns:1fr 1fr;}}
@media(max-width:500px){.fg-grid{grid-template-columns:1fr;}}

/* ── SHARED ── */
.btn-main{
  display:inline-flex;align-items:center;justify-content:center;gap:9px;
  padding:14px 26px;border-radius:13px;
  background:var(--teal);color:#fff;
  font-size:15px;font-weight:800;text-decoration:none;transition:.2s;
  cursor:pointer;font-family:'Plus Jakarta Sans',sans-serif;letter-spacing:-.2px;
  border:none;box-shadow:0 8px 24px rgba(0,122,110,.24);width:100%;
}
.btn-main:hover{transform:translateY(-2px);box-shadow:0 14px 34px rgba(0,122,110,.34);background:#006b60;}

/* ── ANIMATIONS ── */
.rise{opacity:0;transform:translateY(26px);transition:opacity .6s ease,transform .6s ease;}
.rise.in{opacity:1;transform:translateY(0);}

/* ── DECORATIVE DOTS ── */
.dot-grid{
  position:absolute;pointer-events:none;opacity:.40;
  background-image:radial-gradient(circle,var(--rim2) 1px,transparent 1px);
  background-size:22px 22px;
}
</style>
</head>
<body>

<!-- ══ NAVBAR ══ -->
<nav class="navbar">
  <div class="nav-inner">
    <a href="index.php" class="logo">
      <div class="logo-icon"><i class="fas fa-star"></i></div>
      <span class="logo-name">Progress<em>Mate</em></span>
    </a>
    <div class="nav-links">
      <a href="index.php" class="nl on">Home</a>
      <a href="#features" class="nl">Features</a>
      <a href="#how" class="nl">How It Works</a>
      <a href="#about" class="nl">About</a>
      <a href="#contact" class="nl">Contact</a>
    </div>
    <div class="nav-act">
      <a href="login.php" class="nb nb-ghost">Login</a>
      <a href="register.php" class="nb nb-fill"><i class="fas fa-rocket"></i> Get Started/Register</a>
    </div>
    <button class="nav-burger" id="burger" aria-label="Menu">
      <i class="fas fa-bars" id="bicon"></i>
    </button>
  </div>
  <div class="nav-drawer" id="drawer">
    <a href="index.php" class="nl on">Home</a>
    <a href="#features" class="nl">Features</a>
    <a href="#how" class="nl">How It Works</a>
    <a href="#about" class="nl">About</a>
    <a href="#contact" class="nl">Contact</a>
    <a href="login.php" class="nb nb-ghost">Login</a>
    <a href="register.php" class="nb nb-fill"><i class="fas fa-rocket"></i> Get Started</a>
  </div>
</nav>

<!-- ══ HERO ══ -->
<section class="hero" style="position:relative;overflow:hidden;">
  <div class="dot-grid" style="inset:0;"></div>
  <div class="container">
    <div class="hero-grid">
      <div class="rise">
        <div class="hero-pill">
          <span class="hp-dot"><i class="fas fa-star"></i></span>
          Academic Goal Tracking Platform
        </div>
        <h1 class="hero-h1">Track.<br><span class="glow">Improve.</span><br>Succeed.</h1>
        <p class="hero-p">Your all-in-one platform for goal setting, progress tracking, and academic success. Stay motivated with personalized goals tailored to your journey.</p>
        <div class="hero-btns">
          <a href="register.php" class="hbtn hbtn-main"><i class="fas fa-rocket"></i> Start Your Journey</a>
          <a href="#features" class="hbtn hbtn-out"><i class="fas fa-play-circle"></i> See How It Works</a>
        </div>
        <div class="hero-nums">
          <div><div class="hn-val">10K<span>+</span></div><div class="hn-lbl">Students</div></div>
          <div class="hn-div"></div>
          <div><div class="hn-val">95<span>%</span></div><div class="hn-lbl">Success Rate</div></div>
          <div class="hn-div"></div>
          <div><div class="hn-val">4.8<span>★</span></div><div class="hn-lbl">Rating</div></div>
        </div>
      </div>

      <div class="hero-vis rise" style="transition-delay:.18s;position:relative;">
        <div class="hv-card">
          <div class="hv-bar">
            <span class="hv-title"><i class="fas fa-star" style="color:var(--amber);margin-right:7px;font-size:13px;"></i>My Dashboard</span>
            <div class="hv-dots">
              <div class="hv-dot" style="background:#ef4444;"></div>
              <div class="hv-dot" style="background:#f59e0b;"></div>
              <div class="hv-dot" style="background:#22c55e;"></div>
            </div>
          </div>
          <div class="hv-body">
            <div class="hv-kpis">
              <div class="kpi"><div class="kpi-n" style="color:var(--indigo);">12</div><div class="kpi-l">Total Goals</div></div>
              <div class="kpi"><div class="kpi-n" style="color:var(--teal);">8</div><div class="kpi-l">Completed</div></div>
              <div class="kpi"><div class="kpi-n" style="color:var(--amber);">340</div><div class="kpi-l">Points</div></div>
              <div class="kpi"><div class="kpi-n">7🔥</div><div class="kpi-l">Day Streak</div></div>
            </div>
            <div class="hv-goals">
              <div class="hg">
                <div class="hg-top"><span class="hg-name">Math Exam Prep</span><span class="hg-pct">78%</span></div>
                <div class="hg-bar"><div class="hg-fill" style="width:78%;background:linear-gradient(90deg,var(--teal),#00c4b0);"></div></div>
              </div>
              <div class="hg">
                <div class="hg-top"><span class="hg-name">Daily Study — 2hrs</span><span class="hg-pct" style="color:var(--amber);">55%</span></div>
                <div class="hg-bar"><div class="hg-fill" style="width:55%;background:linear-gradient(90deg,var(--amber),#f97316);"></div></div>
              </div>
              <div class="hg">
                <div class="hg-top"><span class="hg-name">Python Course</span><span class="hg-pct" style="color:var(--indigo);">91%</span></div>
                <div class="hg-bar"><div class="hg-fill" style="width:91%;background:linear-gradient(90deg,var(--indigo),#818cf8);"></div></div>
              </div>
            </div>
          </div>
        </div>
        <div class="hv-badge"><i class="fas fa-trophy"></i> 3 Badges Earned Today!</div>
      </div>
    </div>
  </div>
</section>

<!-- ══ FEATURES ══ -->
<section id="features" class="sec sec-alt">
  <div class="container">
    <div class="sh rise">
      <div class="stag"><i class="fas fa-star"></i> Features</div>
      <h2 class="sh-title">Everything You Need<br>to Succeed</h2>
      <p class="sh-sub">Powerful tools across every category — track performance, build habits, and celebrate wins.</p>
    </div>
    <div class="feat-grid">
      <?php foreach([
        ['fas fa-graduation-cap','var(--teal-lt)','var(--teal)','rgba(0,122,110,.18)','Academic Tracking','Set goals to improve subjects, prepare for exams, or track attendance with weekly milestones.'],
        ['fas fa-book-open','var(--amber-lt)','var(--amber)','rgba(217,119,6,.18)','Study Habits','Build daily routines like Pomodoro sessions with smart reminders and burnout prevention.'],
        ['fas fa-tasks','var(--indigo-lt)','var(--indigo)','rgba(67,56,202,.18)','Assignment Management','Break down tasks, prioritize deadlines, and hit step-by-step milestones on time.'],
        ['fas fa-lightbulb','var(--lime-lt)','var(--lime)','rgba(61,122,0,.18)','Skill Development','Track tools, writing, or public speaking with practice-based milestones and graphs.'],
        ['fas fa-heart','var(--rose-lt)','var(--rose)','rgba(192,57,43,.18)','Personal Growth','Incorporate wellness like exercise or journaling, linked to your academic progress.'],
        ['fas fa-trophy','var(--amber-lt)','var(--amber)','rgba(217,119,6,.18)','Badges & Insights','Earn badges for milestones and view trends with charts, predictions, and reports.'],
      ] as [$icon,$bg,$col,$border,$title,$desc]): ?>
      <div class="fc rise">
        <div class="fc-icon" style="background:<?=$bg?>;color:<?=$col?>;border-color:<?=$border?>;"><i class="<?=$icon?>"></i></div>
        <h3 class="fc-t"><?=$title?></h3>
        <p class="fc-d"><?=$desc?></p>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- ══ HOW IT WORKS ══ -->
<section id="how" class="sec">
  <div class="container">
    <div class="sh rise">
      <div class="stag"><i class="fas fa-route"></i> Process</div>
      <h2 class="sh-title">How ProgressMate Works</h2>
      <p class="sh-sub">Three simple steps to transform your academic journey.</p>
    </div>
    <div class="how-wrap rise">
      <div class="steps">
        <?php foreach([
          ['Choose & Customize Goals','Select from templates across academic or wellness categories and tailor them with your own targets.'],
          ['Track & Update Progress','Log daily actions, hit milestones, and see real-time updates with progress bars and reminders.'],
          ['Earn Achievements','Complete goals to unlock badges, celebrate your wins, and gain insights for improvement.'],
        ] as $i=>[$t,$d]): ?>
        <div class="step rise" style="transition-delay:<?=$i*.1?>s;">
          <div class="sn"><?=$i+1?></div>
          <h3 class="st-t"><?=$t?></h3>
          <p class="st-d"><?=$d?></p>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</section>

<!-- ══ TESTIMONIALS ══ -->
<section class="sec sec-alt">
  <div class="container">
    <div class="sh rise">
      <div class="stag"><i class="fas fa-quote-left"></i> Testimonials</div>
      <h2 class="sh-title">What Students Say</h2>
      <p class="sh-sub">Join thousands of students who transformed their academic journey.</p>
    </div>
    <div class="tg">
      <?php foreach([
        ["ProgressMate's goals helped me boost my math understanding with weekly quizzes — grades improved without stress!",'Sarah Johnson','CS, Stanford University','https://i.pravatar.cc/50?img=1'],
        ["The Pomodoro templates kept me focused daily, and the badges made tracking my progress genuinely fun.",'Michael Chen','Medicine, Johns Hopkins','https://i.pravatar.cc/50?img=2'],
        ["Assignment goals helped me break down projects and meet every deadline — earning badges felt incredible.",'Emily Rodriguez','Business, NYU','https://i.pravatar.cc/50?img=3'],
      ] as [$q,$name,$role,$av]): ?>
      <div class="tc rise">
        <div class="tc-q"><?=$q?></div>
        <div class="tc-auth">
          <img src="<?=$av?>" alt="<?=$name?>" class="tc-av">
          <div><div class="tc-name"><?=$name?></div><div class="tc-role"><?=$role?></div></div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- ══ ABOUT ══ -->
<section id="about" class="sec">
  <div class="container">
    <div class="about-wrap">
      <div class="ab-txt rise">
        <div class="stag" style="margin-bottom:16px;"><i class="fas fa-star"></i> About Us</div>
        <h2 class="sh-title" style="text-align:left;margin-bottom:20px;">Built for<br>Student Success</h2>
        <p>ProgressMate is a comprehensive academic tracking platform designed to help students, educators, and administrators manage tasks, track progress, and achieve academic excellence with secure role-based access.</p>
        <p>Features include real-time progress tracking, deadline management, performance analytics, and collaborative tools — all powered by a robust PHP and MySQL backend.</p>
        <div class="ab-tags">
          <span class="ab-tag"><i class="fas fa-lock" style="color:var(--teal);"></i>Secure</span>
          <span class="ab-tag"><i class="fas fa-bolt" style="color:var(--amber);"></i>Real-time</span>
          <span class="ab-tag"><i class="fas fa-mobile-alt" style="color:var(--indigo);"></i>Responsive</span>
          <span class="ab-tag"><i class="fas fa-users" style="color:var(--lime);"></i>Role-based</span>
        </div>
      </div>
      <div class="ab-nums rise" style="transition-delay:.12s;">
        <div class="an"><div class="an-v">10K+</div><div class="an-l">Active Students</div></div>
        <div class="an"><div class="an-v" style="color:var(--amber);">95%</div><div class="an-l">Goal Completion</div></div>
        <div class="an"><div class="an-v" style="color:var(--indigo);">50+</div><div class="an-l">Achievements</div></div>
        <div class="an"><div class="an-v" style="color:var(--rose);">4.8★</div><div class="an-l">User Rating</div></div>
      </div>
    </div>
  </div>
</section>

<!-- ══ CONTACT ══ -->
<section id="contact" class="sec sec-alt">
  <div class="container">
    <div class="sh rise">
      <div class="stag"><i class="fas fa-envelope"></i> Contact</div>
      <h2 class="sh-title">Get In Touch</h2>
      <p class="sh-sub">Questions or feedback? We'd love to hear from you.</p>
    </div>
    <?php if(isset($_GET['success'])): ?><p class="ok-msg" style="max-width:580px;margin:0 auto 16px;"><?=htmlspecialchars($_GET['success'])?></p><?php endif; ?>
    <?php if(isset($_GET['error'])): ?><p class="err-msg" style="max-width:580px;margin:0 auto 16px;"><?=htmlspecialchars($_GET['error'])?></p><?php endif; ?>
    <form class="cform rise" action="contact.php" method="POST">
      <div class="fg"><label>Name</label><input type="text" name="name" placeholder="Your name" required></div>
      <div class="fg"><label>Email</label><input type="email" name="email" placeholder="you@email.com" required></div>
      <div class="fg"><label>Subject</label><input type="text" name="subject" placeholder="How can we help?"></div>
      <div class="fg"><label>Message</label><textarea name="message" rows="5" placeholder="Your message..." required></textarea></div>
      <button type="submit" class="btn-main"><i class="fas fa-paper-plane"></i> Send Message</button>
    </form>
  </div>
</section>

<!-- ══ CTA ══ -->
<section class="cta-band">
  <div class="container">
    <div class="cta-box rise">
      <h2 class="cta-title">Ready to <span>Transform</span><br>Your Academic Journey?</h2>
      <p class="cta-sub">Join 10,000+ students achieving their goals with ProgressMate's smart tracking and insights.</p>
      <div class="cta-btns">
        <a href="register.php" class="hbtn hbtn-main"><i class="fas fa-user-plus"></i> Start Free Trial</a>
        <a href="login.php" class="hbtn hbtn-out"><i class="fas fa-sign-in-alt"></i> Login</a>
      </div>
      <p class="cta-note">No credit card required.</p>
    </div>
  </div>
</section>

<!-- ══ FOOTER ══ -->
<footer class="footer">
  <div class="container">
    <div class="fg-grid">
      <div>
        <a href="index.php" class="fb-logo">
          <div class="logo-icon" style="width:32px;height:32px;font-size:13px;"><i class="fas fa-star"></i></div>
          <span class="fb-name">Progress<em>Mate</em></span>
        </a>
        <p class="fb-desc">Empowering students to achieve academic goals through smart tracking and actionable insights.</p>
        <div class="socials">
          <a href="#" class="sc"><i class="fab fa-twitter"></i></a>
          <a href="#" class="sc"><i class="fab fa-facebook"></i></a>
          <a href="#" class="sc"><i class="fab fa-instagram"></i></a>
          <a href="#" class="sc"><i class="fab fa-linkedin"></i></a>
          <a href="#" class="sc"><i class="fab fa-github"></i></a>
        </div>
      </div>
      <div class="fc-col">
        <h4>Product</h4>
        <a href="students/dashboard.php" class="fl">Dashboard</a>
        <a href="students/goals.php" class="fl">Goals</a>
        <a href="students/achievements.php" class="fl">Achievements</a>
        <a href="students/profile.php" class="fl">Profile</a>
      </div>
      <div class="fc-col">
        <h4>Company</h4>
        <a href="#about" class="fl">About Us</a>
        <a href="#" class="fl">Careers</a>
        <a href="#" class="fl">Blog</a>
        <a href="#contact" class="fl">Contact</a>
      </div>
      <div class="fc-col">
        <h4>Resources</h4>
        <a href="#" class="fl">Help Center</a>
        <a href="#" class="fl">Documentation</a>
        <a href="#" class="fl">Community</a>
        <a href="#" class="fl">Privacy Policy</a>
      </div>
    </div>
    <div class="foot-bot">
      <p>&copy; 2024 ProgressMate. All rights reserved.</p>
      <div class="foot-blinks">
        <a href="#" class="fl">Privacy</a>
        <a href="#" class="fl">Terms</a>
        <a href="#" class="fl">Cookies</a>
      </div>
    </div>
  </div>
</footer>

<script>
// Mobile nav
const burger = document.getElementById('burger');
const bicon  = document.getElementById('bicon');
const drawer = document.getElementById('drawer');
burger.addEventListener('click', () => {
  const open = drawer.classList.toggle('show');
  bicon.className = open ? 'fas fa-times' : 'fas fa-bars';
});
drawer.querySelectorAll('a').forEach(a => {
  a.addEventListener('click', () => {
    drawer.classList.remove('show');
    bicon.className = 'fas fa-bars';
  });
});

// Scroll animations
const io = new IntersectionObserver(es => {
  es.forEach(e => { if(e.isIntersecting) e.target.classList.add('in'); });
}, { threshold:.10 });
document.querySelectorAll('.rise').forEach(el => io.observe(el));

// Active nav
const secs = document.querySelectorAll('section[id]');
window.addEventListener('scroll', () => {
  let cur = '';
  secs.forEach(s => { if(window.scrollY >= s.offsetTop-90) cur=s.id; });
  document.querySelectorAll('.nl').forEach(l => {
    const h = l.getAttribute('href');
    l.classList.toggle('on', h==='#'+cur||(cur===''&&h==='index.php'));
  });
}, {passive:true});
</script>
</body>
</html>