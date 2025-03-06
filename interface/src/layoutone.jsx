export const LayoutOne = ({ children }) => {

    return (
        <div className="min-h-screen bg-gray-100">
            <header className="bg-white shadow">
                <div className="container mx-auto py-4">
                    <h1 className="text-2xl font-bold text-gray-800">Файл бүртгэлийн менежмент</h1>
                </div>
            </header>
            <main className="container mx-auto py-6">
                {children}
            </main>
        </div>
    )
}