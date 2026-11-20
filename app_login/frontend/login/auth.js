const loginForm = document.getElementById('login-form');
const registerForm = document.getElementById('register-form');
const authMessage = document.getElementById('auth-message');
const passwordInput = document.getElementById('password');
const passwordToggle = document.getElementById('toggle-password');
passwordToggle.addEventListener('click', () => {
    const visible = passwordInput.type === 'password';
    passwordInput.type = visible ? 'text' : 'password';
    passwordToggle.setAttribute('aria-pressed', String(visible));
    const label = visible ? 'Ocultar senha' : 'Mostrar senha';
    passwordToggle.setAttribute('aria-label', label);
    passwordToggle.title = label;
});
Portal.request('bootstrap').then(result => {
    if (result.authenticated) location.replace(result.role === 'logs' ? '/logs' : Portal.page('oracle'));
}).catch(error => {
    authMessage.textContent = error.message;
});
function selectForm(register) {
    loginForm.hidden = register;
    registerForm.hidden = !register;
    authMessage.textContent = '';
}
document.getElementById('show-login').addEventListener('click', () => selectForm(false));
document.getElementById('show-register').addEventListener('click', () => selectForm(true));
document.querySelector('.forgot-link').addEventListener('click', event => {
    event.preventDefault();
    authMessage.textContent = 'Para recuperar sua chave, entre em contato com o organizador.';
});
for (const form of [loginForm, registerForm]) {
    form.addEventListener('submit', async event => {
        event.preventDefault();
        const button = form.querySelector('[type="submit"]');
        button.disabled = true;
        authMessage.textContent = '';
        try {
            if (form === registerForm) {
                const email = document.getElementById('register-email').value;
                await Portal.request('register', {
                    username: document.getElementById('register-username').value,
                    email, password: document.getElementById('register-password').value
                });
                selectForm(false);
                document.getElementById('email').value = email;
                registerForm.reset();
                authMessage.textContent = 'Conta criada. Adentre o reino celeste com suas credenciais.';
            } else {
                const result = await Portal.request('login', {
                    email: document.getElementById('email').value,
                    password: document.getElementById('password').value
                });
                location.assign(result.redirect || Portal.page('oracle'));
            }
        } catch (error) { authMessage.textContent = error.message; }
        finally { button.disabled = false; }
    });
}
