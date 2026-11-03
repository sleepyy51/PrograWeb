const API = '../Backend/api.php';

const request = async (accion, options = {}) => {
    const response = await fetch(`${API}?accion=${accion}`, {
        credentials: 'same-origin',
        ...options,
    });
    const data = await response.json();
    if (!response.ok || !data.ok) throw new Error(data.mensaje || 'No fue posible completar la solicitud.');
    return data;
};

const mostrarError = (elemento, error) => {
    elemento.textContent = error.message;
    elemento.hidden = false;
};

const fecha = (valor) => valor ? new Date(`${valor}T00:00:00`).toLocaleDateString('es-MX') : 'Pendiente';
const html = (valor) => String(valor ?? '').replace(/[&<>"']/g, (caracter) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;',
}[caracter]));

const proteger = async () => {
    try {
        return await request('sesion');
    } catch {
        window.location.href = 'index.html';
        return null;
    }
};

document.addEventListener('DOMContentLoaded', async () => {
    const login = document.querySelector('.login-form');
    const error = document.querySelector('[data-error]');

    if (login) {
        login.addEventListener('submit', async (event) => {
            event.preventDefault();
            error.hidden = true;
            try {
                await request('login', { method: 'POST', body: new FormData(login) });
                window.location.href = 'panel.html';
            } catch (exception) {
                mostrarError(error, exception);
            }
        });
        return;
    }

    const usuario = await proteger();
    if (!usuario) return;

    const cerrar = document.querySelector('[data-logout]');
    cerrar?.addEventListener('click', async (event) => {
        event.preventDefault();
        await request('logout');
        window.location.href = 'index.html';
    });

    const tabla = document.querySelector('[data-convocatorias]');
    if (tabla) {
        const datos = await request('convocatorias');
        tabla.innerHTML = datos.convocatorias.map((item) => `
            <tr>
                <td data-label="Examinar"><a class="check-link" href="examinar-convocatoria.html?id=${encodeURIComponent(item.id)}" aria-label="Examinar ${html(item.nombre)}">✓</a></td>
                <td data-label="Convocatoria">${html(item.nombre)}</td>
                <td data-label="Fecha creada">${fecha(item.fecha_inicio)}</td>
                <td data-label="Responsable">${html(item.responsable)}</td>
            </tr>`).join('');
    }

    const form = document.querySelector('.convocation-form');
    if (form) {
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            error.hidden = true;
            const datos = new FormData(form);
            datos.set('clave', datos.get('claves'));
            try {
                await request('crear-convocatoria', { method: 'POST', body: datos });
                window.location.href = 'confirmacion.html';
            } catch (exception) {
                mostrarError(error, exception);
            }
        });
    }

    const detalle = document.querySelector('[data-detalle]');
    if (detalle) {
        const id = new URLSearchParams(window.location.search).get('id');
        if (!id) {
            window.location.href = 'panel.html';
            return;
        }
        const datos = await request(`convocatoria&id=${encodeURIComponent(id)}`);
        detalle.querySelector('h2').textContent = datos.convocatoria.nombre;
        detalle.querySelector('tbody').innerHTML = datos.proyectos.map((item) => `
            <tr>
                <td data-label="Nombre persona">${html(item.nombre_persona)}</td>
                <td data-label="Código">${html(item.codigo)}</td>
                <td data-label="Fecha ingreso">${fecha(item.fecha_ingreso)}</td>
                <td data-label="Nombre proyecto">${html(item.nombre_proyecto)}</td>
                <td data-label="Fecha terminado">${fecha(item.fecha_terminado)}</td>
            </tr>`).join('');
    }
});
