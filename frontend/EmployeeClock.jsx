import React, { useEffect, useMemo, useState } from 'react';

const APP_ROOT = window.location.pathname.split('/').slice(0, 2).join('/') || '/';
const buildUrl = (path) => {
  const root = APP_ROOT === '/' ? '' : APP_ROOT;
  const normalized = path.startsWith('/') ? path : '/' + path;
  return (root + normalized).replace(/\/\/{2,}/g, '/');
};

export function EmployeeClock() {
  const [token] = useState(localStorage.getItem('bf_token'));
  const [user, setUser] = useState(() => JSON.parse(localStorage.getItem('bf_user') || '{}'));
  const [status, setStatus] = useState({ has_open_shift: false });
  const [loading, setLoading] = useState(false);
  const [form, setForm] = useState({ nombre: user.nombre || '', apellidos: user.apellidos || '', dni: user.dni_nie || '' });
  const [message, setMessage] = useState(null);
  const [history, setHistory] = useState({ records: [], page: 1, total_pages: 1 });

  const apiFetch = async (url, method = 'GET', body = null) => {
    const opts = {
      method,
      headers: {
        'Content-Type': 'application/json',
        Authorization: `Bearer ${token}`,
      },
    };
    if (body) opts.body = JSON.stringify(body);

    const res = await fetch(buildUrl(url), opts);
    if (res.status === 401) {
      localStorage.clear();
      window.location.href = buildUrl('/');
      return null;
    }
    return res.json();
  };

  const currentAction = useMemo(() => (status.has_open_shift ? 'salida' : 'entrada'), [status.has_open_shift]);

  useEffect(() => {
    if (!token) {
      window.location.href = buildUrl('/');
      return;
    }
    if (user.rol === 'admin') {
      window.location.href = buildUrl('/admin');
      return;
    }
    loadStatus();
    loadHistory(1);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [token, user]);

  const loadStatus = async () => {
    const data = await apiFetch('/api/time/status');
    if (data?.success) setStatus(data.data);
  };

  const loadHistory = async (page = 1) => {
    const data = await apiFetch(`/api/time/history?page=${page}`);
    if (data?.success) setHistory(data.data);
  };

  const handleChange = (key) => (event) => {
    setForm((prev) => ({ ...prev, [key]: event.target.value }));
  };

  const handleClock = async () => {
    if (!form.nombre.trim() || !form.apellidos.trim() || !form.dni.trim()) {
      setMessage({ type: 'error', text: 'Nombre, apellidos y DNI/NIE son obligatorios.' });
      return;
    }

    setLoading(true);
    const endpoint = status.has_open_shift ? '/api/time/clock-out' : '/api/time/clock-in';
    const result = await apiFetch(endpoint, 'POST', form);
    setLoading(false);

    if (!result?.success) {
      setMessage({ type: 'error', text: result?.message || 'Error al registrar el fichaje.' });
      return;
    }

    setMessage({ type: 'success', text: result.message });
    loadStatus();
    loadHistory(history.page);
  };

  const badgeClass = status.has_open_shift ? 'dentro' : 'fuera';
  const badgeText = status.has_open_shift ? 'En turno' : 'Fuera del turno';

  return (
    <div className="clock-page">
      <header className="topbar">
        <div className="topbar-brand">Bar Fichaje <span>Empleado</span></div>
        <div className="topbar-user"><strong>{user.nombre} {user.apellidos}</strong></div>
      </header>
      <main className="main">
        <section className="fichar-section">
          <div className="section-title">Control horario</div>
          <div className="section-sub">Registra tu entrada y salida con firma obligatoria.</div>
          <div className={`status-badge ${badgeClass}`}><span className="dot"></span>{badgeText}</div>
          <div className="field"><label>Nombre</label><input type="text" value={form.nombre} onChange={handleChange('nombre')} /></div>
          <div className="field"><label>Apellidos</label><input type="text" value={form.apellidos} onChange={handleChange('apellidos')} /></div>
          <div className="field"><label>DNI / NIE</label><input type="text" value={form.dni} onChange={handleChange('dni')} /></div>
          <button className="btn btn-gold" onClick={handleClock} disabled={loading}>
            {loading ? 'Procesando...' : status.has_open_shift ? 'Registrar Salida' : 'Registrar Entrada'}
          </button>
          {message && <div className={`modal-msg show ${message.type}`}>{message.text}</div>}
        </section>
        <section className="history-section">
          <div className="history-header"><h2>Historial</h2></div>
          <div className="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Fecha</th>
                  <th>Entrada</th>
                  <th>Salida</th>
                  <th>Horas</th>
                  <th>Estado</th>
                  <th>Firma</th>
                </tr>
              </thead>
              <tbody>
                {history.records.length === 0 ? (
                  <tr><td colSpan="6">No hay registros todavía.</td></tr>
                ) : history.records.map((r) => (
                  <tr key={r.id}>
                    <td>{r.fecha}</td>
                    <td>{r.hora_entrada}</td>
                    <td>{r.hora_salida}</td>
                    <td>{r.horas_trabajadas}</td>
                    <td>{r.estado}</td>
                    <td>{r.firma_entrada}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </section>
      </main>
    </div>
  );
}
