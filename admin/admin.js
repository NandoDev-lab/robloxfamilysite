// Mantém a autenticação e o estado do painel administrativo privados ao módulo.
(() => {
    const loginSection = document.querySelector(".admin-login")
    const loginForm = document.querySelector(".admin-login-form")
    const loginButton = loginForm.querySelector('button[type="submit"]')
    const logoutButton = document.querySelector(".admin-logout")
    const dashboard = document.querySelector(".admin-dashboard")
    const dashboardLogout = document.querySelector(".dashboard-logout")
    const participantList = document.querySelector(".participants")
    const summary = document.querySelector(".dashboard-summary")
    const status = document.querySelector(".admin-status")

    const authUrl = "/backend/auth.php"
    const adminUrl = "/backend/admin.php"
    let csrfToken = ""
    let adminUser = null
    let refreshTimer = 0
    let refreshInProgress = false

    // Uniformiza as chamadas da API e interrompe solicitações sem resposta.
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

    // Oculta os dados administrativos sempre que a sessão deixa de ser válida.
    const showLogin = (message = "") => {
        window.clearTimeout(refreshTimer)
        adminUser = null
        dashboard.hidden = true
        loginSection.hidden = false
        loginForm.hidden = false
        logoutButton.hidden = true
        status.textContent = message
    }

    // Solicita a invalidação da sessão ao servidor antes de voltar ao login.
    const signOut = async () => {
        try {
            const payload = await requestJson(authUrl, {
                method: "POST",
                headers: { "X-CSRF-Token": csrfToken },
                body: JSON.stringify({ action: "logout" })
            })
            csrfToken = payload.csrfToken
            loginForm.reset()
            showLogin("Sessão encerrada.")
        } catch (error) {
            console.error("Admin logout failed:", error)
            status.textContent = error.message
        }
    }

    // Associa cada controle a uma ação validada novamente pelo endpoint PHP.
    const createActionButton = (label, action, participant, className = "") => {
        const button = document.createElement("button")
        button.type = "button"
        button.textContent = label
        if (className) {
            button.classList.add(className)
        }
        button.addEventListener("click", () => performAction(action, participant))
        return button
    }

    // Pede confirmação para ações destrutivas e atualiza a lista após executá-las.
    const performAction = async (action, participant) => {
        const confirmations = {
            block: `Bloquear ${participant.name} e encerrar todas as sessões?`,
            terminate_session: `Encerrar esta sessão de ${participant.name}?`
        }
        if (confirmations[action] && !window.confirm(confirmations[action])) {
            return
        }

        try {
            await requestJson(adminUrl, {
                method: "POST",
                headers: { "X-CSRF-Token": csrfToken },
                body: JSON.stringify({
                    action,
                    userId: participant.userId,
                    ...(action === "terminate_session" ? { sessionId: participant.sessionId } : {})
                })
            })
            await refreshParticipants()
        } catch (error) {
            console.error("Moderation action failed:", error)
            summary.textContent = error.message
        }
    }

    // Agrupa várias sessões do mesmo membro em um único cartão de moderação.
    const renderParticipants = (participants) => {
        participantList.replaceChildren()
        const users = new Map()
        for (const participant of participants) {
            if (!users.has(participant.userId)) {
                users.set(participant.userId, { ...participant, sessions: [] })
            }
            if (participant.sessionId !== null) {
                users.get(participant.userId).sessions.push(participant)
            }
        }

        if (users.size === 0) {
            const empty = document.createElement("p")
            empty.className = "empty-state"
            empty.textContent = "Nenhum participante online ou aguardando desbloqueio."
            participantList.appendChild(empty)
            summary.textContent = "A lista é atualizada automaticamente."
            return
        }

        const fragment = document.createDocumentFragment()
        for (const participant of users.values()) {
            const card = document.createElement("article")
            const title = document.createElement("h2")
            const details = document.createElement("p")
            const actions = document.createElement("div")
            const sessions = document.createElement("div")
            title.textContent = participant.name
            details.className = "participant-details"
            details.textContent = `${participant.email} · ${participant.blocked ? "Bloqueado" : participant.muted ? "Silenciado" : "Ativo"}`
            actions.className = "participant-actions"
            sessions.className = "participant-sessions"

            actions.appendChild(createActionButton(
                participant.muted ? "Remover silêncio" : "Silenciar",
                participant.muted ? "unmute" : "mute",
                participant
            ))
            actions.appendChild(createActionButton(
                participant.blocked ? "Desbloquear" : "Bloquear",
                participant.blocked ? "unblock" : "block",
                participant,
                participant.blocked ? "" : "danger-action"
            ))

            if (participant.sessions.length === 0) {
                const offline = document.createElement("p")
                offline.className = "participant-details"
                offline.textContent = "Sem sessões online."
                sessions.appendChild(offline)
            } else {
                for (const activeSession of participant.sessions) {
                    const row = document.createElement("div")
                    const lastSeen = document.createElement("span")
                    row.className = "session-row"
                    lastSeen.textContent = `Sessão ativa · atividade ${activeSession.lastSeen} UTC`
                    row.append(
                        lastSeen,
                        createActionButton("Encerrar sessão", "terminate_session", activeSession)
                    )
                    sessions.appendChild(row)
                }
            }

            card.className = "participant-card"
            card.append(title, details, actions, sessions)
            fragment.appendChild(card)
        }
        participantList.appendChild(fragment)
        summary.textContent = `${users.size} membro(s) online ou com moderação pendente.`
    }

    // Evita consultas concorrentes e mantém a lista atualizada periodicamente.
    const refreshParticipants = async () => {
        if (!adminUser || refreshInProgress) {
            return
        }
        refreshInProgress = true
        try {
            const payload = await requestJson(adminUrl)
            if (!Array.isArray(payload.participants)) {
                throw new Error("Resposta inválida do painel.")
            }
            renderParticipants(payload.participants)
        } catch (error) {
            console.error("Could not refresh participant list:", error)
            summary.textContent = error.message
            if (error.message.includes("Acesso restrito") || error.message.includes("sessão")) {
                showLogin(error.message)
            }
        } finally {
            refreshInProgress = false
            if (adminUser) {
                refreshTimer = window.setTimeout(refreshParticipants, 10000)
            }
        }
    }

    // Permite visualizar o painel somente para contas com papel administrativo.
    const enterDashboard = (account) => {
        if (account.role !== "admin") {
            showLogin("Esta conta não tem permissão administrativa.")
            logoutButton.hidden = false
            return
        }
        adminUser = account
        loginSection.hidden = true
        dashboard.hidden = false
        refreshParticipants()
    }

    // A autenticação é compartilhada com o site, mas a API confere o papel admin.
    loginForm.addEventListener("submit", async (event) => {
        event.preventDefault()
        loginButton.disabled = true
        status.textContent = "Verificando acesso..."
        const formData = new FormData(loginForm)
        try {
            const payload = await requestJson(authUrl, {
                method: "POST",
                headers: { "X-CSRF-Token": csrfToken },
                body: JSON.stringify({
                    action: "login",
                    email: formData.get("email"),
                    password: formData.get("password")
                })
            })
            enterDashboard(payload.user)
        } catch (error) {
            console.error("Admin login failed:", error)
            status.textContent = error.message
        } finally {
            loginButton.disabled = false
        }
    })
    logoutButton.addEventListener("click", signOut)
    dashboardLogout.addEventListener("click", signOut)

    // Reaproveita uma sessão administrativa já autenticada neste navegador.
    const initialize = async () => {
        try {
            const payload = await requestJson(authUrl)
            csrfToken = payload.csrfToken
            if (payload.user) {
                enterDashboard(payload.user)
            }
        } catch (error) {
            console.error("Could not initialize admin authentication:", error)
            status.textContent = error.message
        }
    }

    initialize()
})()
