function togglePass(fieldId, btn) {
    const field = document.getElementById(fieldId);
    if (field.type === 'password') {
        field.type = 'text';
        btn.innerHTML = '&#128064;';
    } else {
        field.type = 'password';
        btn.innerHTML = '&#128065;';
    }
}
