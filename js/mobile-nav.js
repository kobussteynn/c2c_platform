(() => {
    const navbars = document.querySelectorAll(".app-navbar");
    if (!navbars.length) {
        return;
    }

    navbars.forEach((navbar) => {
        const toggle = navbar.querySelector(".nav-toggle");
        const actions = navbar.querySelector(".nav-actions");

        if (!toggle || !actions) {
            return;
        }

        toggle.addEventListener("click", () => {
            const opened = navbar.classList.toggle("nav-open");
            toggle.setAttribute("aria-expanded", opened ? "true" : "false");
        });

        actions.querySelectorAll("a").forEach((link) => {
            link.addEventListener("click", () => {
                if (window.innerWidth <= 760) {
                    navbar.classList.remove("nav-open");
                    toggle.setAttribute("aria-expanded", "false");
                }
            });
        });
    });

    document.addEventListener("click", (event) => {
        navbars.forEach((navbar) => {
            if (!navbar.contains(event.target)) {
                navbar.classList.remove("nav-open");
                const toggle = navbar.querySelector(".nav-toggle");
                if (toggle) {
                    toggle.setAttribute("aria-expanded", "false");
                }
            }
        });
    });

    window.addEventListener("resize", () => {
        if (window.innerWidth > 760) {
            navbars.forEach((navbar) => {
                navbar.classList.remove("nav-open");
                const toggle = navbar.querySelector(".nav-toggle");
                if (toggle) {
                    toggle.setAttribute("aria-expanded", "false");
                }
            });
        }
    });
})();
