// Conecta o envio do formulário do quiz à área acessível de resultado.
const quizForm = document.querySelector("#quiz-form")
const quizResult = document.querySelector("#result")

quizForm.addEventListener("submit", (event) => {
    event.preventDefault()

    // Exige uma escolha antes de apresentar o resultado ao visitante.
    const selectedAnswer = quizForm.querySelector('input[name="q1"]:checked')
    if (!selectedAnswer) {
        quizResult.textContent = "Selecione uma opção antes de enviar."
        return
    }

    // Traduz o valor do botão selecionado para o nome exibido no resultado.
    const games = {
        a: "Anime Tycoon",
        b: "Blox Fruit",
        c: "Brookhaven"
    }

    quizResult.textContent = `Você escolheu: ${games[selectedAnswer.value]}.`
})