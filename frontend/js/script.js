// Mantém o estado do bate-papo isolado e evita variáveis globais no navegador.
(() => {
    const login = document.querySelector(".login")
    const loginForm = login.querySelector(".login-form")
    const registerForm = login.querySelector(".register-form")
    const authSwitch = login.querySelector(".auth-switch")
    const authTitle = login.querySelector(".auth-title")
    const authMessage = login.querySelector(".auth-message")
    const chat = document.querySelector(".chat")
    const chatForm = chat.querySelector(".chat-form")
    const chatInput = chat.querySelector(".chat-input")
    const chatMessages = chat.querySelector(".chat-messages")
    const chatButton = chatForm.querySelector(".chat-button")
    const chatStatus = document.querySelector(".chat-status")
    const chatUser = document.querySelector(".chat-user")
    const logoutButton = chat.querySelector(".logout-button")

    // Centraliza os endpoints para facilitar manutenção da integração PHP.
    const api = {
        auth: "/backend/auth.php",
        chat: "/backend/chat.php"
    }
    const pollInterval = 3000
    const maxRenderedMessages = 150
    let csrfToken = ""
    let user = null
    let lastMessageId = 0
    let pollingEnabled = false
    let isMuted = false

    // Padroniza respostas JSON, erros HTTP e limite de espera das solicitações.
    const requestJson = async (url, options = {}) => {
        const controller = new AbortController()
        const timeout = window.setTimeout(() => controller.abort(), 10000)
        try {
            const response = await fetch(url, {
                ...options,
                cache: "no-store",
                signal: controller.signal,
                headers: {
                    ...(options.body ? { "Content-Type": "application/json" } : {}),
                    ...(options.headers || {})
                }
            })
            let payload
            try {
                payload = await response.json()
            } catch (error) {
                throw new Error("O servidor retornou uma resposta inválida.")
            }
            if (!response.ok) {
                throw new Error(payload.error || `Falha na solicitação (${response.status}).`)
            }
            return payload
        } catch (error) {
            if (controller.signal.aborted) {
                throw new Error("O tempo de resposta do servidor expirou.")
            }
            throw error
        } finally {
            window.clearTimeout(timeout)
        }
    }

    const setAuthMessage = (message) => {
        authMessage.textContent = message
    }

    // Limpa o estado visual e local ao sair ou perder uma sessão revogada.
    const showLogin = () => {
        pollingEnabled = false
        user = null
        lastMessageId = 0
        chatMessages.replaceChildren()
        chat.style.display = "none"
        login.style.display = "block"
        chatStatus.textContent = ""
        chatInput.disabled = true
        chatButton.disabled = true
    }

    // Abre o chat apenas depois que o servidor confirma uma conta autenticada.
    const enterChat = (account) => {
        user = account
        login.style.display = "none"
        chat.style.display = "flex"
        chatUser.textContent = `Conectado como ${account.name}`
        chatStatus.textContent = "Conectando ao bate-papo..."
        setAuthMessage("")
        lastMessageId = 0
        chatMessages.replaceChildren()
        pollingEnabled = true
        pollMessages()
    }

    // Cria mensagens apenas com nós de texto para não interpretar HTML recebido.
    const createMessage = (message) => {
        const wrapper = document.createElement("div")
        const content = document.createTextNode(message.content)

        if (Number(message.userId) === user.id) {
            wrapper.classList.add("message-self")
            wrapper.appendChild(content)
        } else {
            const sender = document.createElement("span")
            sender.classList.add("message-sender")
            sender.style.color = /^#[\da-f]{6}$/i.test(message.userColor)
                ? message.userColor
                : "#fa8d0f"
            sender.textContent = message.userName
            wrapper.classList.add("message-other")
            wrapper.append(sender, content)
        }

        return wrapper
    }

    // Evita duplicatas e limita o DOM para manter o chat leve em sessões longas.
    const showMessage = (message) => {
        const messageId = Number(message.id)
        if (!Number.isSafeInteger(messageId) || messageId <= lastMessageId) {
            return
        }

        chatMessages.appendChild(createMessage(message))
        while (chatMessages.children.length > maxRenderedMessages) {
            chatMessages.firstElementChild.remove()
        }
        chatMessages.scrollTop = chatMessages.scrollHeight
        lastMessageId = messageId
    }

    // A consulta também renova a atividade da sessão e atualiza o estado de silêncio.
    const pollMessages = async () => {
        if (!pollingEnabled) {
            return
        }

        try {
            const payload = await requestJson(`${api.chat}?after=${lastMessageId}`)
            if (!Array.isArray(payload.messages)) {
                throw new Error("Resposta inválida do serviço de bate-papo.")
            }

            payload.messages.forEach(showMessage)
            isMuted = payload.muted === true
            chatInput.disabled = isMuted
            chatButton.disabled = isMuted
            chatStatus.textContent = isMuted
                ? "Sua conta está silenciada. Você ainda pode acompanhar o chat."
                : "Conectado ao bate-papo."
        } catch (error) {
            console.error("Chat polling failed:", error)
            pollingEnabled = false
            if (error.message.includes("Entre na sua conta") ||
                error.message.includes("encerrada") ||
                error.message.includes("bloqueada")) {
                showLogin()
                setAuthMessage(error.message)
                return
            }
            chatStatus.textContent = error.message
        }

        if (pollingEnabled) {
            window.setTimeout(pollMessages, pollInterval)
        }
    }

    // Usa o mesmo fluxo seguro para login e cadastro, variando apenas a ação.
    const authenticate = async (event, action) => {
        event.preventDefault()
        const form = event.currentTarget
        const button = form.querySelector('button[type="submit"]')
        const formData = new FormData(form)
        button.disabled = true
        setAuthMessage("Aguarde...")

        try {
            const payload = await requestJson(api.auth, {
                method: "POST",
                headers: { "X-CSRF-Token": csrfToken },
                body: JSON.stringify({
                    action,
                    username: formData.get("username"),
                    email: formData.get("email"),
                    password: formData.get("password")
                })
            })
            enterChat(payload.user)
        } catch (error) {
            console.error("Authentication failed:", error)
            setAuthMessage(error.message)
        } finally {
            button.disabled = false
        }
    }

    // Encerra a sessão no servidor antes de limpar a interface do usuário.
    const handleLogout = async () => {
        logoutButton.disabled = true
        try {
            const payload = await requestJson(api.auth, {
                method: "POST",
                headers: { "X-CSRF-Token": csrfToken },
                body: JSON.stringify({ action: "logout" })
            })
            csrfToken = payload.csrfToken
            loginForm.reset()
            registerForm.reset()
            showLogin()
        } catch (error) {
            console.error("Logout failed:", error)
            chatStatus.textContent = error.message
        } finally {
            logoutButton.disabled = false
        }
    }

    // Alterna os formulários sem navegar nem recarregar a página.
    authSwitch.addEventListener("click", () => {
        const showRegistration = registerForm.hidden
        registerForm.hidden = !showRegistration
        loginForm.hidden = showRegistration
        authSwitch.textContent = showRegistration ? "Já tenho uma conta" : "Criar uma conta"
        authTitle.textContent = showRegistration ? "Criar conta" : "Entrar no bate-papo"
        setAuthMessage("")
    })

    loginForm.addEventListener("submit", (event) => authenticate(event, "login"))
    registerForm.addEventListener("submit", (event) => authenticate(event, "register"))
    // Envia somente o conteúdo; nome, cor e identificador vêm da sessão no servidor.
    chatForm.addEventListener("submit", async (event) => {
        event.preventDefault()
        const content = chatInput.value.trim()
        if (!content || !user || isMuted) {
            return
        }

        chatButton.disabled = true
        try {
            await requestJson(api.chat, {
                method: "POST",
                headers: { "X-CSRF-Token": csrfToken },
                body: JSON.stringify({ content })
            })
            chatInput.value = ""
            chatStatus.textContent = "Mensagem enviada."
        } catch (error) {
            console.error("Sending chat message failed:", error)
            chatStatus.textContent = error.message
        } finally {
            chatButton.disabled = isMuted
            chatInput.focus()
        }
    })
    logoutButton.addEventListener("click", handleLogout)

    // Restaura uma sessão válida ao abrir ou atualizar a página do chat.
    const initialize = async () => {
        try {
            const payload = await requestJson(api.auth)
            csrfToken = payload.csrfToken
            if (payload.user) {
                enterChat(payload.user)
            }
        } catch (error) {
            console.error("Could not initialize authentication:", error)
            setAuthMessage(error.message)
        }
    }

    chatInput.disabled = true
    chatButton.disabled = true
    initialize()
})()
