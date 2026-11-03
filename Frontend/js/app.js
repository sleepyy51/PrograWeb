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

const cargarPayPal = (clientId, currency) => new Promise((resolve, reject) => {
    const script = document.createElement('script');
    script.src = `https://www.paypal.com/sdk/js?client-id=${encodeURIComponent(clientId)}&currency=${encodeURIComponent(currency)}&intent=capture`;
    script.onload = resolve;
    script.onerror = () => reject(new Error('No fue posible cargar PayPal.'));
    document.head.appendChild(script);
});

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
        const nuevoProyecto = detalle.querySelector('[data-nuevo-proyecto]');
        nuevoProyecto.href = `crear-proyecto.html?convocatoria_id=${encodeURIComponent(id)}`;
    }

    const proyectoForm = document.querySelector('.project-form');
    if (proyectoForm) {
        const convocatoriaId = new URLSearchParams(window.location.search).get('convocatoria_id');
        if (!convocatoriaId) {
            window.location.href = 'panel.html';
            return;
        }
        const paypalContainer = document.querySelector('#paypal-button-container');
        const paymentStatus = document.querySelector('[data-payment-status]');
        const amount = document.querySelector('[data-paypal-amount]');
        const saveButton = document.querySelector('[data-save-project]');
        let paypalOrderId = '';

        try {
            const config = await request('paypal-config');
            amount.textContent = `${config.paypal.amount} ${config.paypal.currency}`;
            await cargarPayPal(config.paypal.client_id, config.paypal.currency);
            window.paypal.Buttons({
                createOrder: async () => {
                    if (!proyectoForm.reportValidity()) {
                        throw new Error('Completa los datos del proyecto antes de pagar.');
                    }
                    const datos = new FormData();
                    datos.set('convocatoria_id', convocatoriaId);
                    const orden = await request('paypal-crear-orden', { method: 'POST', body: datos });
                    return orden.order_id;
                },
                onApprove: async (data) => {
                    const datos = new FormData();
                    datos.set('convocatoria_id', convocatoriaId);
                    datos.set('order_id', data.orderID);
                    const pago = await request('paypal-capturar-orden', { method: 'POST', body: datos });
                    paypalOrderId = pago.order_id;
                    saveButton.disabled = false;
                    paymentStatus.textContent = 'Pago completado. Ya puedes guardar el proyecto.';
                },
                onError: (paypalError) => mostrarError(error, paypalError),
            }).render(paypalContainer);
        } catch (exception) {
            mostrarError(error, exception);
            return;
        }
        proyectoForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            error.hidden = true;
            const datos = new FormData(proyectoForm);
            datos.set('convocatoria_id', convocatoriaId);
            datos.set('paypal_order_id', paypalOrderId);
            try {
                await request('crear-proyecto', { method: 'POST', body: datos });
                window.location.href = `examinar-convocatoria.html?id=${encodeURIComponent(convocatoriaId)}`;
            } catch (exception) {
                mostrarError(error, exception);
            }
        });
    }
});
