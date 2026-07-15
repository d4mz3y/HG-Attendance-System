import api from './api';

/**
 * @param {number} staffPk - Internal staff primary key (route model id)
 * @param {'qr' | 'barcode'} kind
 */
export async function downloadStaffCodePng(staffPk, kind) {
    const res = await api.get(`/staff/${staffPk}/codes/${kind}`, { responseType: 'blob' });
    const header = res.headers['content-disposition'] ?? res.headers['Content-Disposition'];
    let filename = `HoganGuards_staff_${staffPk}_${kind}.png`;
    if (header) {
        const m = /filename\*=UTF-8''([^;]+)|filename="([^"]+)"|filename=([^;\s]+)/i.exec(header);
        const raw = decodeURIComponent((m?.[1] || m?.[2] || m?.[3] || '').trim());
        if (raw) {
            filename = raw;
        }
    }
    const url = window.URL.createObjectURL(new Blob([res.data]));
    const link = document.createElement('a');
    link.href = url;
    link.setAttribute('download', filename);
    document.body.appendChild(link);
    link.click();
    link.remove();
    window.URL.revokeObjectURL(url);
}
