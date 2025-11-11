# WP Auto Posts v0.5

**WP Auto Posts** adalah plugin WordPress untuk menjadwalkan dan menerbitkan ribuan artikel otomatis dari file CSV.  
Cocok untuk proyek mass content, SEO, atau manajemen artikel besar-besaran.

---

## 🚀 Fitur Utama
- 📤 Upload CSV besar (otomatis dipecah menjadi batch)
- 🕓 Scheduler fleksibel: Action Scheduler / WP-Cron
- ✨ Template judul & konten dengan variabel `{{kolom}}`
- 🏷️ Auto kategori, tag, dan thumbnail
- 🧩 Dashboard proyek + log aktivitas
- 🔁 Requeue / Force Run task gagal
- 🧱 Aman untuk ribuan task (non-blocking)

---

## ⚙️ Instalasi
1. Upload folder `wp-auto-posts` ke:
/wp-content/plugins/
2. Aktifkan melalui menu **Plugins → Installed Plugins**.
3. Folder `/assets/` dan file `wpap-admin.js` & `wpap-admin.css` akan dibuat otomatis saat aktivasi.

---

## 🧠 Cara Pakai
1. Buka menu **WP Auto Posts** di dashboard admin.
2. Buat proyek baru → upload file `.csv`.
3. Tentukan template judul, konten, kategori, tag, dan thumbnail.
4. Simpan proyek → plugin otomatis membuat *task posting*.
5. Gunakan tombol **Force Run** atau biarkan sistem menjadwalkan otomatis.

---

## 🧩 Struktur Plugin
wp-auto-posts/
├─ wp-auto-posts.php
└─ assets/
├─ wpap-admin.js
└─ wpap-admin.css

---

## 🧑‍💻 Info Plugin
- **Versi:** 0.5  
- **Lisensi:** GPL v2 atau yang lebih baru  
- **Prefix internal:** `wpap_`  
- **Kompatibilitas:** WordPress 6.0+, PHP 7.4+

---

## 🧾 Changelog
**v0.5**
- Penambahan batch processor CSV  
- Fallback otomatis ke WP-Cron  
- Auto generator JS & CSS admin  
- Dashboard proyek dan log task  

---

> 💡 *Plugin ini dibuat agar publikasi massal artikel WordPress jadi mudah, cepat, dan efisien.*

