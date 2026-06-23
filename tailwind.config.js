import defaultTheme from "tailwindcss/defaultTheme";
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
                brand: {
                    50: "#f5f8ff",
                    100: "#e8eeff",
                    500: "#3455db",
                    600: "#2644bd",
                    700: "#1d3493",
                },
            },
        },
    },

    plugins: [forms],
};
