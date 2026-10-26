import { initializeApp } from "firebase/app";
import { getMessaging } from "firebase/messaging";

// Konfigurasi web app Firebase Anda
const firebaseConfig = {
  apiKey: "AIzaSyB2WiyULzzBK1HPUZXkfjFe6zCY_Txj_Ds",
  authDomain: "sistem-cuti-kementan.firebaseapp.com",
  projectId: "sistem-cuti-kementan",
  storageBucket: "sistem-cuti-kementan.firebasestorage.app",
  messagingSenderId: "26077878602",
  appId: "1:26077878602:web:33b940e37f4a70d7c589b3"
};

// Inisialisasi Firebase
const app = initializeApp(firebaseConfig);

// Inisialisasi Firebase Cloud Messaging dan ekspor
const messaging = getMessaging(app);

export { app, messaging };