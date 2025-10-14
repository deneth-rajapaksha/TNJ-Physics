document.getElementById("loginBtn").onclick = () => {
  window.location.href = "/lms/login"; // change to your LMS link
};

document.getElementById("signupBtn").onclick = () => {
  alert("Please contact class admin to join!");
  document.querySelector("#contact").scrollIntoView({ behavior: "smooth" });
};
// Random Shooting Stars
function createShootingStar() {
  const star = document.createElement("div");
  star.classList.add("shooting-star");

  // Randomize angle between 35° and 55°
  const angle = 35 + Math.random() * 20;
  star.style.transform = `rotate(${angle}deg)`;

  // Randomize length between 100px and 200px
  const length = 100 + Math.random() * 100;
  star.style.width = `${length}px`;

  // Random start position
  const startX = Math.random() * window.innerWidth * 0.95;
  const startY = Math.random() * window.innerHeight * 0.4;
  star.style.top = `${startY}px`;
  star.style.left = `${startX}px`;

  document.body.appendChild(star);

  // Remove after animation
  setTimeout(() => star.remove(), 1500);
}

// Increase frequency and allow multiple per interval
setInterval(() => {
  const count = 1 + Math.floor(Math.random() * 3); // 1-3 stars per interval
  for (let i = 0; i < count; i++) {
    if (Math.random() < 0.7) createShootingStar(); // 70% chance per star
  }
}, 1200);
