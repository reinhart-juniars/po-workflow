import defaultTheme from "tailwindcss/defaultTheme";
import colors from "tailwindcss/colors";
import forms from "@tailwindcss/forms";

/** @type {import('tailwindcss').Config} */
module.exports = {
    content: [
        "./resources/views/**/*.blade.php",
        "./resources/js/**/*.js",
        "./resources/**/*.vue",
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['"Public Sans"', ...defaultTheme.fontFamily.sans],
                body: ['"Public Sans"', ...defaultTheme.fontFamily.sans],
                display: ['"Space Grotesk"', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                // Sama persis dengan palet panel Filament (AdminPanelProvider):
                // abu = slate, brand = shade yang Filament hasilkan dari #3455db.
                // Dengan begitu sidebar/bilah Blade dan Filament satu warna.
                gray: colors.slate,
                brand: {
                    50: "#f5f7fd",
                    100: "#ebeefb",
                    200: "#ccd5f6",
                    300: "#aebbf1",
                    400: "#7188e6",
                    500: "#3455db",
                    600: "#2f4dc5",
                    700: "#2740a4",
                    800: "#1f3383",
                    900: "#192a6b",
                    950: "#101a42",
                },
            },
        },
    },

    plugins: [forms],
};
